<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Authentication;
use Grav\Common\User\DataUser\User as DataUser;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Framework\Flex\Interfaces\FlexCollectionInterface;
use Grav\Plugin\Api\Auth\ApiKeyManager;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\FlexBackend;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

class UsersController extends AbstractApiController
{
    use FlexBackend;

    /** 8 MB cap — a profile avatar shouldn't be anywhere near this. */
    private const AVATAR_MAX_SIZE = 8_388_608;

    /**
     * Client-side renderers a plugin column may name. The server only ever
     * declares *which* formatter renders a value; the rendering itself is the
     * client's job. No renderer function or HTML crosses the wire, so a plugin
     * can't smuggle markup or behaviour through a column. Unknown values fall
     * back to 'text'.
     */
    private const COLUMN_FORMATTERS = ['text', 'link', 'date', 'datetime', 'boolean', 'number', 'badge'];

    /** Hard caps so a misbehaving plugin can't bloat a list response. */
    private const COLUMN_MAX_FIELDS = 32;
    private const COLUMN_VALUE_MAX_LEN = 2048;

    private ?UserSerializer $serializer = null;

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        // Without api.users.read a caller can still see *their own* row —
        // we auto-filter the listing to self rather than 403 the request.
        // Anything beyond that requires api.users.read.
        $currentUser = $this->getUser($request);
        $canSeeAll = $this->isSuperAdmin($currentUser)
            || $this->hasPermission($currentUser, 'api.users.read');

        if (!$canSeeAll) {
            return $this->indexSelfOnly($request, $currentUser);
        }

        $directory = $this->getFlexDirectory('user-accounts');
        if ($directory) {
            return $this->indexViaFlex($request, $directory);
        }
        return $this->indexViaAccounts($request);
    }

    /**
     * GET /users/filters — the tab definitions for the Users-list nav row.
     *
     * Restores a capability admin-classic had: plugins can add tabs to the
     * Users page (e.g. "Active", "Licensed") that narrow the listing. A tab is
     * declared via the `onApiUserListFilters` event; selecting it adds
     * `?filter=<id>` to GET /users, which fires `onApiUserListFilter` to narrow
     * the collection before pagination.
     *
     * Tab format (mirrors the sidebar item contract):
     *   [
     *     'id'        => 'active',          // selected via ?filter=active; 'all' is reserved
     *     'plugin'    => 'my-plugin',       // owning plugin slug
     *     'label'     => 'Active',          // display name (raw text, not an i18n key)
     *     'icon'      => 'fa-bolt',         // optional FA icon class
     *     'priority'  => 10,                // optional sort order (higher = earlier)
     *     'badge'     => null,              // optional static badge text/count
     *     'badgeEndpoint' => '/my/count',   // optional — API path returning { count: N }
     *     'authorize' => 'api.users.read',  // optional — string or array for any-of
     *   ]
     *
     * A plugin may also set two page-level policies on the event itself (not on
     * an individual tab):
     *   $event['defaultFilter'] = 'active';  // tab the client lands on with no ?filter
     *   $event['showAll']       = false;     // suppress the built-in "All Users" tab
     *
     * The built-in "All Users" tab (id `all`) leads the row and is the default
     * landing view unless a plugin overrides these, and selecting it sends no
     * `filter` param.
     *
     * Response shape:
     *   { "tabs": [ ... ], "defaultFilter": "active", "showAll": true }
     */
    public function filters(ServerRequestInterface $request): ResponseInterface
    {
        // Tabs only mean something to a caller who can list users; a self-only
        // caller has nothing to filter.
        $this->requirePermission($request, 'api.users.read');

        $user = $this->getUser($request);
        $event = $this->fireEvent('onApiUserListFilters', [
            'filters' => [],
            'defaultFilter' => null,
            'showAll' => true,
            'user' => $user,
        ]);

        return ApiResponse::create($this->assembleFilterTabs($event, $user));
    }

    /**
     * GET /users/columns — plugin-declared extra columns for the Users list.
     *
     * The presentation half of the Users extension contract (getgrav/
     * grav-plugin-admin2#111). A plugin declares columns via the
     * `onApiUserListColumns` event; Admin2 owns the table, and the per-user
     * values ride along inside each user's `extra` map on GET /users (populated
     * by `onApiUserListColumnData`, scoped to the current page — never a
     * parallel all-users fetch).
     *
     * Column format:
     *   [
     *     'id'        => 'my-plugin-valid-till', // required, unique; client key
     *     'plugin'    => 'my-plugin',            // owning plugin slug
     *     'label'     => 'Valid until',          // display name (raw text)
     *     'field'     => 'subscription.valid_till', // key into each user's `extra`
     *     'formatter' => 'datetime',             // one of COLUMN_FORMATTERS; else 'text'
     *     'labelField' => 'my-plugin.link_label', // optional for formatter=link; key for visible link text
     *     'sortable'  => false,                  // client-side, current page only
     *     'priority'  => 50,                     // optional sort order (higher = earlier)
     *     'authorize' => 'api.users.read',       // optional — string or array for any-of
     *   ]
     *
     * Deliberately narrow: scalar data only, a fixed formatter whitelist, no
     * raw HTML or renderer functions. That keeps plugin columns from becoming
     * the kind of open-ended surface that let classic-admin plugins break on
     * upgrade.
     *
     * Response shape: { "columns": [ ... ] }
     */
    public function columns(ServerRequestInterface $request): ResponseInterface
    {
        // Columns only mean something to a caller who can list users.
        $this->requirePermission($request, 'api.users.read');

        $user = $this->getUser($request);
        $event = $this->fireEvent('onApiUserListColumns', [
            'columns' => [],
            'user' => $user,
        ]);

        return ApiResponse::create(['columns' => $this->assembleColumns($event, $user)]);
    }

    /**
     * Validate and normalize plugin-declared columns: drop malformed entries and
     * columns the caller isn't authorized for, whitelist the formatter, sanitize
     * the field key, then order by descending priority. Mirrors
     * assembleFilterTabs() — the `authorize` field is a server-side annotation
     * and is stripped before the column reaches the client.
     *
     * @param Event $event The onApiUserListColumns event after plugins ran
     * @return array<int, array<string, mixed>>
     */
    private function assembleColumns(Event $event, UserInterface $user): array
    {
        $isSuperAdmin = $this->isSuperAdmin($user);

        $columns = [];
        $seen = [];
        foreach ((array) ($event['columns'] ?? []) as $column) {
            if (!is_array($column) || !isset($column['id']) || !is_string($column['id']) || $column['id'] === '') {
                continue;
            }
            if (isset($seen[$column['id']])) {
                continue; // first declaration of an id wins
            }
            if (!$this->userPassesAuthorize($user, $column['authorize'] ?? null, $isSuperAdmin)) {
                continue;
            }

            // The field is the key looked up in each user's `extra` map. Keep it
            // to a safe identifier charset — no traversal or odd keys.
            $field = isset($column['field']) && is_string($column['field'])
                ? preg_replace('/[^A-Za-z0-9_.\-]/', '', $column['field'])
                : '';
            if ($field === '') {
                continue;
            }

            $formatter = isset($column['formatter']) && is_string($column['formatter'])
                && in_array($column['formatter'], self::COLUMN_FORMATTERS, true)
                    ? $column['formatter']
                    : 'text';

            $labelField = isset($column['labelField']) && is_string($column['labelField'])
                ? preg_replace('/[^A-Za-z0-9_.\-]/', '', $column['labelField'])
                : '';

            $seen[$column['id']] = true;
            $column['field'] = $field;
            $column['formatter'] = $formatter;
            $column['sortable'] = (bool) ($column['sortable'] ?? false);
            if ($formatter === 'link' && $labelField !== '') {
                $column['labelField'] = $labelField;
            } else {
                unset($column['labelField']);
            }
            // Strip the authorize field — server-side annotation, not client data.
            unset($column['authorize']);
            $columns[] = $column;
        }

        usort($columns, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $columns;
    }

    /**
     * Merge plugin-owned scalar column data into an already-serialized,
     * already-paginated page of users. Fired ONCE for the whole page (the
     * `onApiUserListColumnData` event receives only the usernames already
     * selected after search / filter / permission / pagination), so there is no
     * N+1 and no incentive to load metadata for every account.
     *
     * Hard isolation: a throwing or misbehaving subscriber can never 500 or
     * stall the listing — failures degrade to missing column values, logged as
     * a warning. Plugin values are scalar-only and capped.
     *
     * @param array<int, array<string, mixed>> $data Serialized users for this page
     * @return array<int, array<string, mixed>>
     */
    private function applyColumnData(array $data, UserInterface $currentUser): array
    {
        if ($data === []) {
            return $data;
        }

        $usernames = array_values(array_filter(array_column($data, 'username'), 'is_string'));
        if ($usernames === []) {
            return $data;
        }

        try {
            $event = $this->fireEvent('onApiUserListColumnData', [
                'usernames' => $usernames,
                'data' => [], // plugin fills: username => [ field => scalar ]
                'user' => $currentUser,
            ]);

            $map = $event['data'] ?? null;
            if (!is_array($map) || $map === []) {
                return $data;
            }

            foreach ($data as &$row) {
                $extra = $map[$row['username']] ?? null;
                if (is_array($extra)) {
                    $clean = $this->sanitizeColumnValues($extra);
                    if ($clean !== []) {
                        $row['extra'] = $clean;
                    }
                }
            }
            unset($row);
        } catch (\Throwable $e) {
            // Isolation: a plugin fault must not break the users list.
            $this->grav['log']->warning('[api] onApiUserListColumnData failed: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * Enforce the column-data contract on one user's plugin values: scalars (or
     * null) only — arrays, objects and resources are rejected so a plugin can't
     * leak blobs or nested structures — with a safe key charset and per-value
     * and per-user size caps.
     *
     * @param array<mixed, mixed> $extra
     * @return array<string, string|int|float|bool|null>
     */
    private function sanitizeColumnValues(array $extra): array
    {
        $clean = [];
        foreach ($extra as $key => $value) {
            if (count($clean) >= self::COLUMN_MAX_FIELDS) {
                break;
            }
            if (!is_string($key)) {
                continue;
            }
            $key = preg_replace('/[^A-Za-z0-9_.\-]/', '', $key);
            if ($key === '') {
                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                continue; // scalar-only: drop arrays/objects/resources
            }
            if (is_string($value) && strlen($value) > self::COLUMN_VALUE_MAX_LEN) {
                $value = substr($value, 0, self::COLUMN_VALUE_MAX_LEN);
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Merge plugin-contributed Users tabs with the built-in "All Users" tab,
     * dropping malformed entries and tabs the caller isn't authorized for, then
     * ordering by descending priority. The `all` id is reserved so a plugin
     * can't shadow it. Returns the tab row alongside the resolved landing-view
     * policy (defaultFilter / showAll) for the client.
     *
     * @param Event $event The onApiUserListFilters event after plugins ran
     * @return array{tabs: array<int, array<string, mixed>>, defaultFilter: string, showAll: bool}
     */
    private function assembleFilterTabs(Event $event, UserInterface $user): array
    {
        $isSuperAdmin = $this->isSuperAdmin($user);

        $tabs = [];
        foreach ((array) ($event['filters'] ?? []) as $tab) {
            if (!is_array($tab) || !isset($tab['id']) || !is_string($tab['id']) || $tab['id'] === '') {
                continue;
            }
            if ($tab['id'] === 'all') {
                continue; // reserved for the built-in tab
            }
            if (!$this->userPassesAuthorize($user, $tab['authorize'] ?? null, $isSuperAdmin)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data.
            unset($tab['authorize']);
            $tabs[] = $tab;
        }

        usort($tabs, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        // A plugin can suppress the built-in "All Users" tab when showing every
        // account isn't a sensible (or safe) landing view — but only once it has
        // contributed at least one authorized tab of its own, so the row can
        // never end up empty.
        $showAll = ($event['showAll'] ?? true) !== false;
        if ($showAll || $tabs === []) {
            array_unshift($tabs, [
                'id' => 'all',
                'plugin' => 'api',
                'label' => 'All Users',
            ]);
        }

        // Resolve the landing tab. Honour a plugin's defaultFilter only when it
        // maps to a tab the caller can actually see; otherwise fall back to the
        // first tab in the row (which is "all" whenever it's present).
        $ids = array_column($tabs, 'id');
        $requested = $event['defaultFilter'] ?? null;
        $defaultFilter = (is_string($requested) && in_array($requested, $ids, true))
            ? $requested
            : ($ids[0] ?? 'all');

        return [
            'tabs' => $tabs,
            'defaultFilter' => $defaultFilter,
            'showAll' => in_array('all', $ids, true),
        ];
    }

    /**
     * Single-row "listing" for callers without api.users.read. Matches the
     * paginated envelope of the full listing so the client doesn't need a
     * special-case branch.
     */
    private function indexSelfOnly(ServerRequestInterface $request, UserInterface $currentUser): ResponseInterface
    {
        $pagination = $this->getPagination($request);
        $data = [$this->serializeUser($currentUser)];

        return ApiResponse::paginated(
            data: $data,
            total: 1,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/users',
        );
    }

    /**
     * List users using the Flex-Objects backend (indexed, searchable).
     */
    private function indexViaFlex(ServerRequestInterface $request, FlexDirectory $directory): ResponseInterface
    {
        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $search = $query['search'] ?? null;
        $filters = $this->getListFilters($request);

        // Grav's Flex FileStorage indexes every file in user/accounts/ without
        // filtering by extension — any stray file left there by another plugin
        // (e.g. revisions-pro's `name.yaml.<timestamp>.rev` snapshots) surfaces
        // as a phantom user. Constrain to keys that look like actual usernames
        // before the collection is built so downstream search/sort/pagination
        // operate on real accounts only.
        //
        // Usernames may legitimately contain periods (DataUser::isValidUsername
        // allows them, and so does POST /users), so we can't simply reject dots
        // — that hid accounts like `bill.bailey`. Instead accept anything that
        // is a valid username but drop keys that embed a stored-file extension
        // (`.yaml`/`.json`), which is the tell-tale of a revision/backup stray.
        $index = $directory->getIndex();
        $validKeys = array_values(array_filter(
            $index->getKeys(),
            static fn($k) => is_string($k)
                && DataUser::isValidUsername($k)
                && !preg_match('/\.(ya?ml|json)(\.|$)/i', $k),
        ));
        $collection = $directory->getCollection($validKeys);

        // Apply search (searches username, email, fullname per blueprint config)
        if ($search && $search !== '') {
            $collection = $collection->search($search);
        }

        // Sort by username by default
        $collection = $collection->sort(['username' => 'asc']);

        // Plugin-contributed Users-tab filter (e.g. an "Active" or "Licensed"
        // tab from onApiUserListFilters). Fired AFTER search/sort but BEFORE
        // permission/group filtering and pagination, so a tab can only *narrow*
        // the collection — core still applies the caller's access scope and
        // paginates the result, meaning a plugin tab can never widen visibility
        // or break the response envelope. The plugin owning $filter assigns the
        // narrowed collection back to the event; anything else is ignored.
        if ($filters['filter'] !== '') {
            $event = $this->fireEvent('onApiUserListFilter', [
                'filter' => $filters['filter'],
                'collection' => $collection,
                'query' => $query,
                'user' => $this->getUser($request),
            ]);
            $narrowed = $event['collection'] ?? null;
            if ($narrowed instanceof FlexCollectionInterface) {
                $collection = $narrowed;
            }
        }

        if ($filters['access'] === '' && $filters['group'] === '') {
            // No permission/group filter — keep the lazy, indexed fast path that
            // only materializes the requested page.
            $total = $collection->count();
            $slice = $collection->slice($pagination['offset'], $pagination['limit']);

            $data = [];
            foreach ($slice as $flexUser) {
                if ($flexUser instanceof UserInterface) {
                    $data[] = $this->serializeUser($flexUser);
                }
            }
        } else {
            // Permission/group filtering can't be expressed as an indexed query
            // (it depends on effective access, including group inheritance and
            // the superuser fallback), so materialize the ordered users and
            // filter in PHP before paginating. Search above already narrowed
            // the set.
            $users = [];
            foreach ($collection as $flexUser) {
                if ($flexUser instanceof UserInterface && $this->userMatchesFilters($flexUser, $filters)) {
                    $users[] = $flexUser;
                }
            }

            $total = count($users);
            $data = [];
            foreach (array_slice($users, $pagination['offset'], $pagination['limit']) as $flexUser) {
                $data[] = $this->serializeUser($flexUser);
            }
        }

        // Let plugins attach their declared column values to this page of
        // users (getgrav/grav-plugin-admin2#111). Scoped to the served page,
        // applied after pagination — the indexed fast path above is untouched.
        $data = $this->applyColumnData($data, $this->getUser($request));

        return ApiResponse::paginated(
            data: $data,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/users',
        );
    }

    /**
     * List users using filesystem scan (fallback).
     */
    private function indexViaAccounts(ServerRequestInterface $request): ResponseInterface
    {
        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $search = isset($query['search']) ? trim((string) $query['search']) : '';
        $filters = $this->getListFilters($request);

        $allUsers = [];
        foreach ($this->getAllUsernames() as $username) {
            $user = $this->grav['accounts']->load($username);
            if (!$user->exists()) {
                continue;
            }
            if ($search !== '' && !$this->userMatchesSearch($user, $search)) {
                continue;
            }
            if (!$this->userMatchesFilters($user, $filters)) {
                continue;
            }
            $allUsers[] = $this->serializeUser($user);
        }

        $total = count($allUsers);
        $paged = array_slice($allUsers, $pagination['offset'], $pagination['limit']);

        // Column data is resolved for the served page only — never $allUsers —
        // so a plugin isn't pushed into loading metadata for every account.
        $paged = $this->applyColumnData($paged, $this->getUser($request));

        return ApiResponse::paginated(
            data: $paged,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/users',
        );
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        // Self-access mirrors update(): a user can fetch their own record
        // with just api.access. Otherwise api.users.read is required to see
        // someone else's account.
        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.read');
        } else {
            $this->requirePermission($request, 'api.access');
        }

        $user = $this->loadUserOrFail($username);

        $data = $this->serializeUser($user);

        // ETag is computed from the user data only — system capability flags
        // like twofa_global_enabled are not part of the resource state and
        // shouldn't cause spurious 409s on PATCH when the admin flips the
        // global setting between fetch and save.
        $etag = $this->generateEtag($data);

        // Offer 2FA enrollment whenever the capability is present (Login plugin
        // installed). Previously this keyed off `plugins.login.twofa_enabled`,
        // which defaults to false, so the enroll panel was hidden on a stock
        // 2.0 install and 2FA could not be configured from admin2 at all
        // (getgrav/grav#4145).
        $data['twofa_global_enabled'] = class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class);

        return ApiResponse::create($data, 200, ['ETag' => '"' . $etag . '"']);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password', 'email']);

        $username = $body['username'];

        // Validate username format. Delegate the character rules to the core
        // helper (Grav\Common\User\DataUser\User::isValidUsername) so the API
        // accepts exactly what admin-classic does: letters, numbers, periods,
        // hyphens and underscores, while still blocking path traversal,
        // leading dots and filesystem-dangerous characters. Keep a 3-64 length
        // bound for a friendlier message and to match the admin-next UI hint.
        $length = mb_strlen((string) $username);
        if ($length < 3 || $length > 64 || !DataUser::isValidUsername((string) $username)) {
            throw new ValidationException(
                'Invalid username format.',
                [['field' => 'username', 'message' => 'Username must be 3-64 characters and contain only letters, numbers, periods, hyphens, and underscores (and cannot start with a period).']],
            );
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $existing = $accounts->load($username);

        if ($existing->exists()) {
            throw new ConflictException("User '{$username}' already exists.");
        }

        // Create new user
        $user = $accounts->load($username);
        $user->set('email', $body['email']);
        $user->set('fullname', $body['fullname'] ?? '');
        $user->set('title', $body['title'] ?? '');
        $user->set('state', $body['state'] ?? 'enabled');
        $user->set('hashed_password', Authentication::create($body['password']));
        $user->set('created', time());
        $user->set('modified', time());

        if (isset($body['access'])) {
            // A non-super creator must not mint a super-admin account — granting
            // super is a tier the caller does not hold. See GHSA-p97c-g455-q447.
            if (!$this->isSuperAdmin($this->getUser($request)) && $this->accessGrantsSuper($body['access'])) {
                throw new ForbiddenException('Granting super-admin access requires super-admin privileges.');
            }
            $user->set('access', $body['access']);
        }

        // `groups` is super-admin-only (see update()): group membership can grant
        // access, so a non-super creator must not seed group assignments.
        if (isset($body['groups']) && $this->isSuperAdmin($this->getUser($request))) {
            $user->set('groups', $body['groups']);
        }

        // Allow plugins to modify the user before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$user]);

        // Validate the submitted fields against the account blueprint before
        // writing to disk (admin2#30) — e.g. a password that fails the
        // configured pwd_regex, or a required field sent empty, now returns 422.
        $this->validateChangedFields($body, method_exists($user, 'getBlueprint') ? $user->getBlueprint() : null);

        $user->save();

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $user]);
        $this->fireEvent('onApiUserCreated', ['user' => $user]);

        return ApiResponse::created(
            data: $this->serializeUser($user),
            location: $this->getApiBaseUrl() . '/users/' . $username,
            headers: $this->invalidationHeaders(['users:create:' . $username, 'users:list']),
        );
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $currentUser = $this->getUser($request);
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        // Users can update themselves with just api.access, otherwise need api.users.write
        $isSelf = $currentUser->username === $username;
        $canManageUsers = $this->isSuperAdmin($currentUser)
            || $this->hasPermission($currentUser, 'api.users.write');
        if (!$isSelf) {
            $this->requirePermission($request, 'api.users.write');
        } else {
            // Self-edit only requires api.access (already checked by auth middleware)
            $this->requirePermission($request, 'api.access');
        }

        // Prevent privilege escalation (IDOR): a non-super manager must not modify
        // a super-admin account. Holding api.users.write authorizes managing users,
        // not acting on a higher-privilege target — otherwise a delegated user-manager
        // could overwrite the super-admin's password (via the password field below,
        // which sits outside the per-field permission gate) and seize the instance.
        // The target check covers both super flags (admin.super and api.super): a
        // classic admin.super account may not carry api.super. See GHSA-p97c-g455-q447.
        $isSuper = $this->isSuperAdmin($currentUser);
        if (!$isSuper && $this->accessGrantsSuper($user->get('access'))) {
            throw new ForbiddenException('Only super-admins can modify super-admin accounts.');
        }

        // ETag validation
        $currentHash = $this->generateEtag($this->serializeUser($user));
        $this->validateEtag($request, $currentHash);

        $body = $this->getRequestBody($request);

        if (empty($body)) {
            throw new ValidationException('Request body must contain fields to update.');
        }

        // Privilege-sensitive fields are gated on api.users.write. Without this
        // split a self-edit (api.access only) could PATCH `access` and grant
        // itself api.super / admin.super — see GHSA-r945-h4vm-h736.
        $selfFields  = ['email', 'fullname', 'title', 'language', 'content_editor', 'twofa_enabled'];
        $adminFields = ['state', 'access'];
        // `groups` is marked `security@: admin.super` in the account blueprint:
        // group membership can confer access, so only super admins may change it
        // — a plain api.users.write manager must not assign users into groups.
        $superFields = ['groups'];

        if (!$canManageUsers) {
            foreach ($adminFields as $field) {
                if (array_key_exists($field, $body)) {
                    throw new ForbiddenException(
                        "Modifying '{$field}' requires the 'api.users.write' permission."
                    );
                }
            }
        }

        if (!$isSuper) {
            foreach ($superFields as $field) {
                if (array_key_exists($field, $body)) {
                    throw new ForbiddenException(
                        "Modifying '{$field}' requires super-admin privileges."
                    );
                }
            }

            // A non-super manager may edit `access` (it's an admin field), but must
            // not use it to grant super — that would promote an account to a tier
            // the caller does not hold. See GHSA-p97c-g455-q447.
            if (isset($body['access']) && $this->accessGrantsSuper($body['access'])) {
                throw new ForbiddenException('Granting super-admin access requires super-admin privileges.');
            }
        }

        $allowedFields = $selfFields;
        if ($canManageUsers) {
            $allowedFields = array_merge($allowedFields, $adminFields);
        }
        if ($isSuper) {
            $allowedFields = array_merge($allowedFields, $superFields);
        }
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $body)) {
                $user->set($field, $body[$field]);
            }
        }

        // Hash password if provided
        $passwordChanged = isset($body['password']) && $body['password'] !== '';
        if ($passwordChanged) {
            $user->set('hashed_password', Authentication::create($body['password']));
        }

        // Invalidate every outstanding API token for this account when its
        // password changes or it gets disabled. Stamping the cutoff is the kill
        // switch JwtAuthenticator checks on each request, so a stolen access or
        // refresh token can't outlive a password reset or account lockout.
        // GHSA-m8g9-wxhx-6f86.
        $disabledNow = in_array('state', $allowedFields, true)
            && array_key_exists('state', $body)
            && $user->get('state') === 'disabled';
        if ($passwordChanged || $disabledNow) {
            $user->set('api_tokens_valid_after', time());
        }

        $user->set('modified', time());

        // Allow plugins to modify the user before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$user]);

        // Validate the submitted fields against the account blueprint before
        // writing to disk (admin2#30).
        $this->validateChangedFields($body, method_exists($user, 'getBlueprint') ? $user->getBlueprint() : null);

        $user->save();

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $user]);
        $this->fireEvent('onApiUserUpdated', ['user' => $user]);

        return $this->respondWithEtag(
            $this->serializeUser($user),
            200,
            ['users:update:' . $username, 'users:list'],
        );
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.users.write');

        $currentUser = $this->getUser($request);
        $username = $this->getRouteParam($request, 'username');

        if ($currentUser->username === $username) {
            throw new ForbiddenException('You cannot delete your own account.');
        }

        $user = $this->loadUserOrFail($username);

        // A non-super manager must not delete a super-admin account — a destructive
        // cross-boundary action (lockout / takeover of the instance owner).
        // See GHSA-p97c-g455-q447.
        if (!$this->isSuperAdmin($currentUser) && $this->accessGrantsSuper($user->get('access'))) {
            throw new ForbiddenException('Only super-admins can delete super-admin accounts.');
        }

        $this->fireEvent('onApiBeforeUserDelete', ['user' => $user]);

        // Remove user file
        $file = $user->file();
        if ($file) {
            $file->delete();
        }

        $this->fireEvent('onApiUserDeleted', ['username' => $username]);

        return ApiResponse::noContent(
            $this->invalidationHeaders(['users:delete:' . $username, 'users:list']),
        );
    }

    /**
     * POST /users/{username}/avatar - Upload a custom avatar image.
     */
    public function uploadAvatar(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['avatar'] ?? $uploadedFiles['file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException('No avatar file uploaded.');
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::AVATAR_MAX_SIZE) {
            throw new ValidationException(
                sprintf('Avatar exceeds maximum size of %d MB.', self::AVATAR_MAX_SIZE / 1_048_576)
            );
        }

        // Validate the ACTUAL image bytes, not the client-declared MIME type.
        // getClientMediaType() is attacker-controlled, so trusting it lets a
        // PHP/SVG/polyglot payload be written to disk with an image extension
        // (GHSA-xc64-vh46-vph6). getimagesizefromstring() only succeeds on a
        // real raster image, and the extension is taken from the detected type.
        $contents = (string) $file->getStream();
        $info = @getimagesizefromstring($contents);
        $ext = match ($info[2] ?? null) {
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_JPEG => 'jpg',
            default => throw new ValidationException(
                'Avatar must be a valid PNG, JPEG, or WebP image.'
            ),
        };
        $mime = (string) $info['mime'];

        // Save to account://avatars/
        $locator = $this->grav['locator'];
        $avatarDir = $locator->findResource('account://', true) . '/avatars';
        if (!is_dir($avatarDir)) {
            mkdir($avatarDir, 0755, true);
        }

        $filename = $username . '-' . substr(md5((string) time()), 0, 8) . '.' . $ext;
        $filepath = $avatarDir . '/' . $filename;
        // Write the validated bytes ourselves rather than moveTo(): we've already
        // read the stream, and this guarantees only the inspected content lands on disk.
        if (file_put_contents($filepath, $contents) === false) {
            throw new \RuntimeException('Failed to write avatar file.');
        }

        // Build path relative to Grav root (e.g. user/accounts/avatars/filename.jpg)
        // to match the format used by the old admin plugin.
        $relativeBase = $locator->findResource('account://', false);
        $relativePath = $relativeBase . '/avatars/' . $filename;

        // Update user's avatar reference
        $user->set('avatar', [
            $relativePath => [
                'name' => $filename,
                'type' => $mime,
                'size' => filesize($filepath),
                'path' => $relativePath,
            ],
        ]);
        $user->save();

        return ApiResponse::create(
            $this->serializeUser($user),
            201,
            $this->invalidationHeaders(['users:update:' . $username]),
        );
    }

    /**
     * DELETE /users/{username}/avatar - Remove the custom avatar.
     */
    public function deleteAvatar(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }

        // Delete avatar file(s)
        $avatar = $user->get('avatar');
        if (is_array($avatar)) {
            foreach ($avatar as $entry) {
                if (is_array($entry) && isset($entry['path'])) {
                    // path is relative to Grav root (e.g. user/accounts/avatars/file.jpg)
                    $filePath = GRAV_ROOT . '/' . $entry['path'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
        }

        $user->set('avatar', []);
        $user->save();

        return ApiResponse::create(
            $this->serializeUser($user),
            200,
            $this->invalidationHeaders(['users:update:' . $username]),
        );
    }

    /**
     * POST /users/{username}/2fa - Generate or regenerate 2FA secret and return QR code.
     */
    public function generate2fa(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        // Self or admin
        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            $this->requirePermission($request, 'api.users.write');
        }
        $this->requireNotSuperTarget($currentUser, $user);

        if (!class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class)) {
            throw new \Grav\Plugin\Api\Exceptions\ApiException(
                500,
                '2FA Not Available',
                'The Login plugin with 2FA support must be installed.'
            );
        }

        $twoFa = new \Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth();
        $secret = $twoFa->createSecret();

        // Format secret with spaces for readability
        $formattedSecret = trim(chunk_split($secret, 4, ' '));

        // Save to user
        $user->set('twofa_secret', $formattedSecret);
        // Generating/regenerating a secret resets the enabled flag — the user
        // must verify a code against the new secret to re-enable.
        $user->set('twofa_enabled', false);
        $user->save();

        // Generate QR code data URI
        $qrImage = $twoFa->getQrImageData($username, $secret);

        return ApiResponse::create([
            'secret' => $formattedSecret,
            'qr_code' => $qrImage,
        ]);
    }

    /**
     * POST /users/{username}/2fa/enable - Verify a code against the stored
     * secret and set twofa_enabled=true. Self-only: only the account owner
     * can enable their own 2FA, because enabling requires proving you hold
     * the secret (otherwise an attacker could lock a user out by enabling
     * 2FA with a secret they don't control).
     */
    public function enable2fa(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        if ($currentUser->username !== $username) {
            throw new ForbiddenException('Only the account owner can enable 2FA.');
        }

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['code']);

        if (!class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class)) {
            throw new \Grav\Plugin\Api\Exceptions\ApiException(
                500,
                '2FA Not Available',
                'The Login plugin with 2FA support must be installed.',
            );
        }

        $secret = (string) $user->get('twofa_secret');
        if ($secret === '') {
            throw new ValidationException('2FA secret has not been generated. POST /users/{username}/2fa first.');
        }

        $twoFa = new \Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth();
        if (!$twoFa->verifyCode($secret, (string) $body['code'])) {
            throw new ValidationException('Invalid 2FA code.');
        }

        $user->set('twofa_enabled', true);
        $user->save();

        $this->fireEvent('onApiUser2faEnabled', ['user' => $user]);

        return ApiResponse::create(['twofa_enabled' => true]);
    }

    /**
     * POST /users/{username}/2fa/disable - Disable 2FA for a user.
     *
     * Self-disable requires a valid current TOTP code so that a stolen
     * session cannot unilaterally remove 2FA. Admins with api.users.write
     * (or superadmin) can force-disable without a code — used for lost-
     * device recovery. Both paths clear twofa_secret.
     */
    public function disable2fa(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $currentUser = $this->getUser($request);
        $isSelf = $currentUser->username === $username;
        $isAdmin = $this->isSuperAdmin($currentUser) || $this->hasPermission($currentUser, 'api.users.write');

        if (!$isSelf && !$isAdmin) {
            throw new ForbiddenException('You do not have permission to disable 2FA for this user.');
        }
        $this->requireNotSuperTarget($currentUser, $user);

        if ($isSelf && !$isAdmin) {
            // Self-disable without admin privilege requires code verification.
            $body = $this->getRequestBody($request);
            $this->requireFields($body, ['code']);

            if (!class_exists(\Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth::class)) {
                throw new \Grav\Plugin\Api\Exceptions\ApiException(
                    500,
                    '2FA Not Available',
                    'The Login plugin with 2FA support must be installed.',
                );
            }

            $secret = (string) $user->get('twofa_secret');
            $twoFa = new \Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth();
            if (!$secret || !$twoFa->verifyCode($secret, (string) $body['code'])) {
                throw new ValidationException('Invalid 2FA code.');
            }
        }

        $user->set('twofa_enabled', false);
        $user->set('twofa_secret', '');
        $user->save();

        $this->fireEvent('onApiUser2faDisabled', [
            'user' => $user,
            'forced_by_admin' => !$isSelf,
        ]);

        return ApiResponse::create(['twofa_enabled' => false]);
    }

    public function apiKeys(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username);

        $manager = new ApiKeyManager();
        $keys = $manager->listKeys($user);

        return ApiResponse::create($keys);
    }

    public function createApiKey(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username, write: true);
        $this->requireNotSuperTarget($this->getUser($request), $user);

        $body = $this->getRequestBody($request);
        $name = $body['name'] ?? '';
        $scopes = $body['scopes'] ?? [];
        $expiryDays = isset($body['expiry_days']) ? (int) $body['expiry_days'] : null;

        $manager = new ApiKeyManager();
        $result = $manager->generateKey($user, $name, $scopes, $expiryDays);

        // Return the raw key (shown ONCE only) along with key metadata
        $keys = $manager->listKeys($user);
        $keyMeta = null;
        foreach ($keys as $key) {
            if ($key['id'] === $result['id']) {
                $keyMeta = $key;
                break;
            }
        }

        $data = array_merge($keyMeta ?? [], ['api_key' => $result['key']]);

        return ApiResponse::created(
            data: $data,
            location: $this->getApiBaseUrl() . '/users/' . $username . '/api-keys',
            headers: $this->invalidationHeaders(['users:update:' . $username . ':api-keys']),
        );
    }

    public function deleteApiKey(ServerRequestInterface $request): ResponseInterface
    {
        $username = $this->getRouteParam($request, 'username');
        $user = $this->loadUserOrFail($username);

        $this->requireApiKeyPermission($request, $username, write: true);
        $this->requireNotSuperTarget($this->getUser($request), $user);

        $keyId = $this->getRouteParam($request, 'keyId');

        $manager = new ApiKeyManager();
        $revoked = $manager->revokeKey($user, $keyId);

        if (!$revoked) {
            throw new NotFoundException("API key '{$keyId}' not found for user '{$username}'.");
        }

        return ApiResponse::noContent(
            $this->invalidationHeaders(['users:update:' . $username . ':api-keys']),
        );
    }

    /**
     * Check permission for API key operations. Own user with api.access is sufficient,
     * otherwise require api.users.read (or api.users.write for mutations).
     */
    private function requireApiKeyPermission(
        ServerRequestInterface $request,
        string $targetUsername,
        bool $write = false,
    ): void {
        $currentUser = $this->getUser($request);
        $isSelf = $currentUser->username === $targetUsername;

        if ($isSelf) {
            // Self-access only requires api.access
            $this->requirePermission($request, 'api.access');
        } else {
            $this->requirePermission($request, $write ? 'api.users.write' : 'api.users.read');
        }
    }

    /**
     * Block a non-super caller from acting on a super-admin target via the
     * per-user sibling endpoints (API keys, 2FA generate/disable). The primary
     * mutators — create()/update()/delete() — already carry this guard; these
     * siblings target /users/{username} too and must not become an escalation
     * path (e.g. minting an API key for, or stripping 2FA from, a super-admin).
     * Acting on your own account is never an escalation, so self is allowed.
     * Callers must pass the already-loaded target user. See GHSA-p97c-g455-q447
     * and GHSA-8gg4.
     */
    private function requireNotSuperTarget(UserInterface $current, UserInterface $target): void
    {
        if ($current->username === $target->username) {
            return;
        }

        if (!$this->isSuperAdmin($current) && $this->accessGrantsSuper($target->get('access'))) {
            throw new ForbiddenException('Only super-admins can manage super-admin accounts.');
        }
    }

    /**
     * Detect whether an `access` payload would confer super-admin privileges
     * (admin.super or api.super), in either nested (`['admin' => ['super' => 1]]`)
     * or dot-keyed (`['admin.super' => 1]`) form.
     *
     * Used to stop a non-super api.users.write manager from minting or promoting
     * a super account, and to detect when a loaded target user is itself super —
     * privilege escalation by proxy. See GHSA-p97c-g455-q447.
     *
     * @param mixed $access
     */
    private function accessGrantsSuper($access): bool
    {
        if (!is_array($access)) {
            return false;
        }

        foreach (['admin', 'api'] as $scope) {
            if (!empty($access[$scope]['super']) || !empty($access["{$scope}.super"])) {
                return true;
            }
        }

        return false;
    }

    private function loadUserOrFail(?string $username): UserInterface
    {
        if ($username === null || $username === '') {
            throw new ValidationException('Username is required.');
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        if (!$user->exists()) {
            throw new NotFoundException("User '{$username}' not found.");
        }

        return $user;
    }

    private function serializeUser(UserInterface $user): array
    {
        return $this->getSerializer()->serialize($user);
    }

    /**
     * Extract the access/group list filters from the request query string.
     *
     * `access` is the canonical permission filter (e.g. `admin.login`,
     * `api.super`); `permission` is accepted as an alias. `group` filters by
     * group membership. `filter` carries the active Users-tab id (see
     * onApiUserListFilters / onApiUserListFilter) — empty means the built-in
     * "All Users" tab and is handled entirely by core.
     *
     * @return array{access: string, group: string, filter: string}
     */
    private function getListFilters(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $access = $query['access'] ?? $query['permission'] ?? '';
        $group = $query['group'] ?? '';
        $filter = $query['filter'] ?? '';

        return [
            'access' => is_string($access) ? trim($access) : '',
            'group' => is_string($group) ? trim($group) : '',
            'filter' => is_string($filter) ? trim($filter) : '',
        ];
    }

    /**
     * @param array{access: string, group: string, filter?: string} $filters
     */
    private function userMatchesFilters(UserInterface $user, array $filters): bool
    {
        if ($filters['group'] !== '') {
            $groups = array_map('strval', (array) $user->get('groups', []));
            if (!in_array($filters['group'], $groups, true)) {
                return false;
            }
        }

        if ($filters['access'] !== '' && !$this->userHasEffectiveAccess($user, $filters['access'])) {
            return false;
        }

        return true;
    }

    /**
     * Test whether a user is effectively granted a permission, independent of
     * login state (so it works against accounts loaded from storage).
     *
     * Resolves the action against the merged access map (group access overlaid
     * by the user's own access) with parent-key inheritance — `api.pages`
     * covers `api.pages.read` — and treats super admins (api.super or the
     * legacy admin.super) as authorized for everything, so "find all admins"
     * catches either authority.
     */
    private function userHasEffectiveAccess(UserInterface $user, string $action): bool
    {
        if ($action === '') {
            return true;
        }

        $flat = $this->effectiveAccessMap($user);

        if ($action !== 'admin.super' && $action !== 'api.super') {
            if ($this->isPositiveFlat($flat, 'api.super') || $this->isPositiveFlat($flat, 'admin.super')) {
                return true;
            }
        }

        // Walk up the dot-path; the closest explicitly-set key wins.
        $key = $action;
        while ($key !== '') {
            if (array_key_exists($key, $flat)) {
                return Utils::isPositive($flat[$key]);
            }
            $pos = strrpos($key, '.');
            $key = $pos !== false ? substr($key, 0, $pos) : '';
        }

        return false;
    }

    /**
     * Build a flattened (dot-notation) access map for the user: each group's
     * access first, then the user's own access on top so direct grants
     * override inherited ones.
     *
     * @return array<string, mixed>
     */
    private function effectiveAccessMap(UserInterface $user): array
    {
        $map = [];

        foreach ((array) $user->get('groups', []) as $group) {
            if (!is_string($group)) {
                continue;
            }
            $groupAccess = $this->config->get("groups.{$group}.access");
            if (is_array($groupAccess)) {
                $map = array_merge($map, Utils::arrayFlattenDotNotation($groupAccess));
            }
        }

        $own = $user->get('access');
        if (is_array($own)) {
            $map = array_merge($map, Utils::arrayFlattenDotNotation($own));
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $flat
     */
    private function isPositiveFlat(array $flat, string $key): bool
    {
        return array_key_exists($key, $flat) && Utils::isPositive($flat[$key]);
    }

    /**
     * Case-insensitive substring match across the searchable user fields,
     * mirroring the Flex backend's blueprint-configured search.
     */
    private function userMatchesSearch(UserInterface $user, string $search): bool
    {
        $needle = mb_strtolower($search);
        $haystacks = [
            (string) $user->username,
            (string) $user->get('email', ''),
            (string) $user->get('fullname', ''),
            (string) $user->get('title', ''),
        ];

        foreach ($haystacks as $value) {
            if ($value !== '' && str_contains(mb_strtolower($value), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function getSerializer(): UserSerializer
    {
        return $this->serializer ??= new UserSerializer();
    }

    /**
     * Get all usernames by scanning account files.
     */
    private function getAllUsernames(): array
    {
        $locator = $this->grav['locator'];

        $accountDir = $locator->findResource('account://', true)
            ?: $locator->findResource('user://accounts', true);

        if (!$accountDir || !is_dir($accountDir)) {
            return [];
        }

        $usernames = [];
        foreach (new \DirectoryIterator($accountDir) as $file) {
            if ($file->isDot() || !$file->isFile() || $file->getExtension() !== 'yaml') {
                continue;
            }
            $usernames[] = $file->getBasename('.yaml');
        }

        sort($usernames);
        return $usernames;
    }
}
