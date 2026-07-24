<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Data\Blueprint;
use Grav\Common\Data\Validation;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Demo\DemoManager;
use Grav\Plugin\Api\Exceptions\DemoModeException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

abstract class AbstractApiController
{
    /**
     * Purpose tag + TTL for the short-lived 2FA challenge token. Defined here
     * (rather than on AuthController) so every login transport that has to
     * mint or honor a 2FA challenge — password login, and the SSO/OAuth login
     * bridge — shares one set of values.
     */
    protected const CHALLENGE_2FA = '2fa_challenge';
    protected const CHALLENGE_TTL = 300;

    public function __construct(
        protected readonly Grav $grav,
        protected readonly Config $config,
    ) {}

    /**
     * Get the authenticated user from the request.
     */
    protected function getUser(ServerRequestInterface $request): UserInterface
    {
        $user = $request->getAttribute('api_user');
        if (!$user) {
            throw new UnauthorizedException();
        }
        return $user;
    }

    /**
     * Verify the user has the required permission.
     */
    protected function requirePermission(ServerRequestInterface $request, string $permission): void
    {
        $user = $this->getUser($request);

        // API-key scope cap (GHSA-x7hm). A key created with a NON-EMPTY `scopes`
        // list is restricted to exactly those permissions, regardless of the
        // owning account's ACL — so a scoped key minted on a super-admin account
        // is still capped. This is enforced BEFORE the super-admin short-circuit
        // below so super keys can't bypass it. An empty/absent scope set (the
        // default, and all JWT/session credentials) means full access.
        $scopes = $request->getAttribute('api_key_scopes');
        if (is_array($scopes) && $scopes !== [] && !$this->scopesPermit($scopes, $permission)) {
            throw new ForbiddenException("API key is not authorized for: {$permission}");
        }

        // Demo write-lock. Keyed on the exact permission being exercised — so
        // page-media (api.media.write) and page-content (api.pages.write) are
        // distinguished correctly even though they share the /pages route prefix.
        // Enforced BEFORE the super-admin short-circuit because a demo account is
        // typically also super. Reads are never blocked (browsing stays open).
        if ($this->isDemoUser($request) && $this->demoWriteBlocked($permission)) {
            throw new DemoModeException();
        }

        // Super admin can do anything
        if ($this->isSuperAdmin($user)) {
            return;
        }

        // Check API access first
        if (!$this->hasPermission($user, 'api.access')) {
            throw new ForbiddenException('API access is not enabled for this user.');
        }

        // Check specific permission
        if (!$this->hasPermission($user, $permission)) {
            throw new ForbiddenException("Missing required permission: {$permission}");
        }
    }

    /**
     * Require super-admin for a privileged write, with the API-key scope cap applied.
     *
     * Prefer this over a bare `isSuperAdmin()` check on super-only endpoints. A
     * bare check reads the authenticated user's super flag directly, so a scoped
     * key minted on a super-admin account passes it and reaches the sink without
     * the `api_key_scopes` cap ever running (GHSA-jqgq-v53x-x99g). Routing through
     * requirePermission() enforces the cap first: an unscoped credential (session,
     * JWT, or unscoped key) on a super account still passes, but a scoped key must
     * carry `admin.super` (or `*`) in its scopes or it is rejected here.
     */
    protected function requireSuper(ServerRequestInterface $request): void
    {
        $this->requirePermission($request, 'admin.super');
    }

    /**
     * Non-throwing form of the API-key scope cap: whether the request's key is
     * permitted to exercise $permission. An unscoped credential (session, JWT, or
     * unscoped key) always returns true; a scoped key must carry the permission
     * (or a parent scope, or `*`) in its scopes.
     *
     * Use this for SOFT gates that strip or branch rather than reject — e.g. a
     * "may this caller grant super?" decision that must fall back to stripping the
     * super flag for a legitimate non-super, instead of throwing. A bare
     * isSuperAdmin() on such a gate skips the cap (GHSA-jqgq-v53x-x99g class).
     */
    protected function scopeAllows(ServerRequestInterface $request, string $permission): bool
    {
        $scopes = $request->getAttribute('api_key_scopes');

        return !is_array($scopes) || $scopes === [] || $this->scopesPermit($scopes, $permission);
    }

    /**
     * Whether the caller is a super-admin AND the API-key scope cap permits super
     * authority. Equivalent to isSuperAdmin() but honoring the scope cap, so a
     * scoped key minted on a super account returns false unless it carries
     * `admin.super` (or `*`). Use where the code needs super-ness as a boolean to
     * assemble a response rather than to hard-gate (which is requireSuper()).
     */
    protected function isSuperWithinScope(ServerRequestInterface $request): bool
    {
        return $this->isSuperAdmin($this->getUser($request))
            && $this->scopeAllows($request, 'admin.super');
    }

    /**
     * Whether a non-empty API-key scope list grants the requested permission.
     *
     * A scope grants its own permission and everything beneath it — scope
     * `api.pages` covers `api.pages.read` — mirroring the parent-key inheritance
     * hasPermission() applies to the account ACL. `*` is an explicit grant-all
     * scope. Callers only invoke this when the scope list is non-empty (an empty
     * list means an unscoped key with full access). See GHSA-x7hm.
     *
     * @param array<int, mixed> $scopes
     */
    private function scopesPermit(array $scopes, string $permission): bool
    {
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '') {
                continue;
            }
            if ($scope === '*' || $scope === $permission || str_starts_with($permission, $scope . '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is an API super user via direct access array lookup.
     *
     * API authority is strictly scoped to access.api.super — admin.super
     * (admin-classic's legacy global super) is intentionally NOT honored
     * here. Grav 2.0 separates admin-classic and API/Admin-Next authority
     * so operators can grant one without implicitly granting the other.
     */
    protected function isSuperAdmin(UserInterface $user): bool
    {
        return (bool) $user->get('access.api.super');
    }

    /**
     * Placeholder substituted for path/secret-revealing values that are hidden
     * from demo accounts (server paths in phpinfo, cron command lines, etc.).
     */
    protected const DEMO_REDACTED = '(hidden in demo mode)';

    /**
     * Whether the request's authenticated user is a demo account
     * (access.api.demo). Demo accounts browse everything but are write-blocked
     * by DemoModeMiddleware and have operational/secret data redacted on read.
     */
    protected function isDemoUser(ServerRequestInterface $request): bool
    {
        $user = $request->getAttribute('api_user');
        return $user instanceof UserInterface && (bool) $user->get('access.api.demo');
    }

    /**
     * Block an endpoint outright for demo accounts. Used for reads that can't be
     * safely redacted field-by-field — raw logs, and backup archives that
     * contain password hashes and config secrets.
     */
    protected function denyIfDemo(ServerRequestInterface $request, string $detail = 'This information is hidden in demo mode.'): void
    {
        if ($this->isDemoUser($request)) {
            throw new DemoModeException($detail);
        }
    }

    /**
     * Whether demo mode blocks the given permission. Read permissions (`*.read`)
     * are always allowed so a demo account can browse everything; any other
     * (mutating) permission is blocked unless it's in `plugins.api.demo.writable`.
     * This is the single source of truth for the writable allowlist — the router
     * middleware is only a coarse fail-closed backstop.
     */
    protected function demoWriteBlocked(string $permission): bool
    {
        if (str_ends_with($permission, '.read')) {
            return false;
        }
        $writable = (array) $this->config->get('plugins.api.demo.writable', []);
        return !in_array($permission, $writable, true);
    }

    /**
     * Check user permission with parent-key inheritance.
     *
     * Granting "api.pages" implicitly covers "api.pages.read" via walk-up
     * resolution, matching how Grav's core ACL resolves permissions.
     */
    protected function hasPermission(UserInterface $user, string $permission): bool
    {
        return (bool) $this->getPermissionResolver()->resolve($user, $permission);
    }

    /**
     * Check whether a user satisfies an `authorize` requirement attached to a
     * sidebar / menubar / widget item. Mirrors admin-classic's pattern:
     *
     *   - `null` (no requirement) → always allowed.
     *   - string → user must have that permission.
     *   - array  → user must have at least ONE of the listed permissions.
     *
     * Super-admins pass regardless of the requirement.
     */
    protected function userPassesAuthorize(UserInterface $user, mixed $authorize, bool $isSuperAdmin): bool
    {
        if ($authorize === null) {
            return true;
        }
        if ($isSuperAdmin) {
            return true;
        }
        if (is_string($authorize)) {
            return $this->hasPermission($user, $authorize);
        }
        if (is_array($authorize)) {
            foreach ($authorize as $perm) {
                if (is_string($perm) && $this->hasPermission($user, $perm)) {
                    return true;
                }
            }
            return false;
        }
        // Unknown shape — fail closed.
        return false;
    }

    private ?PermissionResolver $permissionResolver = null;

    protected function getPermissionResolver(): PermissionResolver
    {
        return $this->permissionResolver ??= new PermissionResolver($this->grav['permissions']);
    }

    /**
     * Get the parsed JSON request body.
     */
    protected function getRequestBody(ServerRequestInterface $request): array
    {
        $body = $request->getAttribute('json_body');
        if ($body === null) {
            $body = $request->getParsedBody();
        }
        return is_array($body) ? $body : [];
    }

    /**
     * List-aware recursive merge of an incoming patch into existing data.
     *
     * Unlike array_replace_recursive, this never merges into list-shaped
     * nodes: if either side at a given key is a sequential list, the
     * incoming value replaces the existing one wholesale. Prevents the
     * "'0','1','2' keys alongside named entries" YAML corruption that
     * array_replace_recursive produces when a YAML list on disk is sent
     * back as a name-keyed map (or vice versa).
     */
    protected function mergePatch(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (
                is_array($value)
                && isset($existing[$key])
                && is_array($existing[$key])
                && !array_is_list($value)
                && !array_is_list($existing[$key])
            ) {
                $existing[$key] = $this->mergePatch($existing[$key], $value);
            } else {
                $existing[$key] = $value;
            }
        }
        return $existing;
    }

    /**
     * Validate only the fields present in `$changes` against their blueprint
     * definitions, throwing the API's ValidationException (HTTP 422) with
     * per-field messages on failure.
     *
     * We validate the submitted delta — NOT the whole merged object — on
     * purpose. Grav's own stock config doesn't pass a whole-object
     * `$blueprint->validate()`: `system.errors.display` ships as a bool against
     * a `type: int` rule, and the core `list` validator rejects complete
     * security/backups/scheduler list items (required per-item sub-fields are
     * checked at the wrong nesting level). All of those landmines live in
     * fields the request never touches, so validating just the changed fields
     * sidesteps them while still rejecting an invalid value or a required field
     * submitted empty (getgrav/grav-plugin-admin2#30). Completeness — a required
     * field the user never filled — is enforced by the admin UI, which renders
     * the whole form.
     *
     * `$changes` is keyed exactly as the blueprint expects (e.g. `errors.display`
     * nested under `errors`, page fields under `header`); it is flattened to the
     * blueprint's leaf fields here.
     *
     * Some clients (notably admin-next's config form) post the WHOLE form rather
     * than just the edited fields. To keep the "validate only what changed"
     * guarantee in that case, pass the persisted `$existing` baseline: any leaf
     * whose submitted value equals the baseline is skipped. Without this, a
     * single pre-existing invalid value — common after a 1.x→2.0 migration —
     * would fail validation on every save and block edits to unrelated fields,
     * even though the user never touched it (getgrav/grav#4176). When `$existing`
     * is empty (other callers that already send a true delta) every submitted
     * leaf is validated, exactly as before.
     *
     * @param array $changes  Incoming values (possibly nested), as sent by the client.
     * @param array $existing Persisted baseline to diff against; [] validates all of $changes.
     */
    protected function validateChangedFields(array $changes, ?Blueprint $blueprint, array $existing = []): void
    {
        if ($blueprint === null || $changes === []) {
            return;
        }

        $schema = $blueprint->schema();
        $errors = [];

        // Baseline leaves, keyed the same way as the flattened changes, so a
        // field the client echoed back untouched can be recognised and skipped.
        $existingLeaves = $existing === [] ? [] : $blueprint->flattenData($existing);

        foreach ($blueprint->flattenData($changes) as $name => $value) {
            // Skip leaves that match what's already persisted: the client sent
            // them but the user did not change them, so re-validating stored
            // (possibly migration-era) data would be wrong. Loose `==` treats a
            // reordered list as changed but an int/bool round-trip as unchanged,
            // which matches Grav's own runtime leniency.
            if (array_key_exists($name, $existingLeaves) && $value == $existingLeaves[$name]) {
                continue;
            }

            $field = $schema->getProperty($name);
            if (!is_array($field) || !isset($field['type'])) {
                // Not a blueprint-defined field (extra/legacy key) — nothing to validate.
                continue;
            }

            $value = $this->coerceForValidation($value, $field);

            foreach (Validation::validate($value, $field) as $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = [
                        'field' => $name,
                        'message' => trim(strip_tags((string) $message)),
                    ];
                }
            }

            // XSS safety gate. The full blueprint validator (BlueprintSchema::validate())
            // runs checkSafety() per field, but this partial-field path validates the
            // submitted delta directly and must enforce the same trust boundary itself —
            // otherwise a non-superadmin editor could persist stored XSS (e.g. an
            // `onerror=` handler in page Markdown) that fires in an admin or visitor
            // session. checkSafety() honors security.xss_whitelist (admin.super) and
            // per-field `xss_check: false`, so behaviour matches the classic admin exactly.
            foreach (Validation::checkSafety($value, $field) as $messages) {
                foreach ((array) $messages as $message) {
                    $errors[] = [
                        'field' => $name,
                        'message' => trim(strip_tags((string) $message)),
                    ];
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException(
                'The submitted data did not pass blueprint validation.',
                $errors,
            );
        }
    }

    /**
     * Mirror Grav's runtime leniency between ints and booleans for int-typed
     * fields. `system.errors.display`, for example, is declared `type: int`
     * but Grav's error handler (Errors::resetHandlers) treats `true`/`false`
     * as `1`/`0`. Grav's `typeInt` validator is stricter (`is_numeric(true)`
     * is false), so without this a legitimate boolean value would be rejected.
     */
    private function coerceForValidation(mixed $value, array $field): mixed
    {
        $type = $field['validate']['type'] ?? $field['type'] ?? null;
        if (is_bool($value) && ($type === 'int' || $type === 'number')) {
            return (int) $value;
        }

        // A `checkboxes` field with `use: keys` stores every option as a
        // key => bool map (e.g. page `process: {markdown: true, twig: false}`).
        // Core's typeArray validates the *keys* against the currently available
        // options, so a key whose option has since been gated out of the
        // blueprint — `twig` once twig-in-content is disabled via
        // Security::pageProcessOptions — fails the options diff and blocks the
        // whole save, even though the user never touched it and `false` means
        // "not enabled" anyway (admin2#41). Drop the disabled keys so only the
        // genuinely-enabled options are validated. The stored value is left
        // intact; this affects validation only.
        if (($field['type'] ?? null) === 'checkboxes'
            && ($field['use'] ?? null) === 'keys'
            && is_array($value)
        ) {
            return array_filter($value);
        }

        return $value;
    }

    /**
     * Get route parameters captured by FastRoute.
     */
    protected function getRouteParam(ServerRequestInterface $request, string $name): ?string
    {
        $params = $request->getAttribute('route_params', []);
        return $params[$name] ?? null;
    }

    /**
     * Resolve a page from a route, with awareness of `system.home.hide_in_urls`.
     *
     * Tries the public route first (so canonical routes always win), then falls
     * back to the structural identifier via rawRoute(). When the home route is
     * hidden, a page at `user/pages/home/<child>` has the public route `/<child>`
     * (home stripped) but a rawRoute of `/home/<child>` — the identifier Admin2
     * uses to address the page. Without the fallback, `find('/home/<child>')`
     * returns null and callers 404 on a page that is editable in the admin
     * (getgrav/grav-plugin-api#10).
     */
    protected function resolvePageByRoute(string $route): ?PageInterface
    {
        $pages = $this->grav['pages'];

        // Enable pages if they were disabled (e.g. in admin context).
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        $needle = '/' . ltrim($route, '/');

        $page = $pages->find($needle);
        if ($page) {
            return $page;
        }

        // Fallback: match the structural route Admin2 uses (e.g. '/home/<child>').
        foreach ($pages->instances() as $candidate) {
            if ($candidate instanceof PageInterface && $candidate->rawRoute() === $needle) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Get pagination parameters from query string.
     */
    protected function getPagination(ServerRequestInterface $request, ?int $defaultPerPage = null): array
    {
        $query = $request->getQueryParams();
        $defaultPerPage = $defaultPerPage ?? (int) $this->config->get('plugins.api.pagination.default_per_page', 20);
        $maxPerPage = $this->config->get('plugins.api.pagination.max_per_page', 1000);

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = min($maxPerPage, max(1, (int) ($query['per_page'] ?? $defaultPerPage)));

        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ];
    }

    /**
     * Get sort parameters from query string.
     */
    protected function getSorting(ServerRequestInterface $request, array $allowedFields = []): array
    {
        $query = $request->getQueryParams();
        $sort = $query['sort'] ?? null;
        $order = strtolower($query['order'] ?? 'asc');

        if ($sort && $allowedFields && !in_array($sort, $allowedFields, true)) {
            throw new ValidationException("Invalid sort field '{$sort}'. Allowed: " . implode(', ', $allowedFields));
        }

        if (!in_array($order, ['asc', 'desc'], true)) {
            $order = 'asc';
        }

        return [
            'sort' => $sort,
            'order' => $order,
        ];
    }

    /**
     * Get filter parameters from query string.
     */
    protected function getFilters(ServerRequestInterface $request, array $allowedFilters = []): array
    {
        $query = $request->getQueryParams();
        $filters = [];

        foreach ($allowedFilters as $filter) {
            // Support dot notation for nested params (e.g., taxonomy.category)
            if (str_contains($filter, '.')) {
                $parts = explode('.', $filter);
                $value = $query;
                foreach ($parts as $part) {
                    $value = $value[$part] ?? null;
                    if ($value === null) {
                        break;
                    }
                }
                if ($value !== null) {
                    $filters[$filter] = $value;
                }
            } elseif (isset($query[$filter])) {
                $filters[$filter] = $query[$filter];
            }
        }

        return $filters;
    }

    /**
     * Validate ETag for optimistic concurrency control.
     * Returns true if the client's ETag matches the current resource hash.
     */
    protected function validateEtag(ServerRequestInterface $request, string $currentHash): void
    {
        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch && $this->normalizeEtag($ifMatch) !== $currentHash) {
            throw new \Grav\Plugin\Api\Exceptions\ConflictException(
                'The resource has been modified since you last retrieved it. Please fetch the latest version and try again.'
            );
        }
    }

    /**
     * Strip transport-layer noise from an inbound ETag so comparisons survive
     * reverse proxies that weaken the header.
     *
     * Apache mod_deflate and some nginx builds append `-gzip` (or `;gzip`) to
     * ETags on compressed responses and leave it in place when the client
     * echoes the value back in If-Match. Weak markers (`W/`) and surrounding
     * quotes are also normalized here so the raw md5 hash is what gets
     * compared against generateEtag()'s output.
     */
    private function normalizeEtag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) {
            $etag = substr($etag, 2);
        }
        $etag = trim($etag, '"');
        // Strip known transport suffixes a compressing front-end appends to the
        // ETag and leaves in place when the client echoes it back in If-Match:
        // mod_deflate `-gzip`/`;gzip`, mod_brotli `-br`, and mod_zstd `-zstd`
        // (the last surfaced as a false 409 in getgrav/grav-plugin-admin2#28).
        $etag = preg_replace('/[-;](?:gzip|br|deflate|zstd)$/i', '', $etag) ?? $etag;
        return $etag;
    }

    /**
     * Generate an ETag hash for a resource.
     */
    protected function generateEtag(mixed $data): string
    {
        return md5(json_encode($data));
    }

    /**
     * Create a response with ETag header, optionally paired with invalidation tags.
     *
     * By default the ETag is hashed from the response body. Pass an explicit
     * $etag when the body and the validator must diverge — e.g. config saves
     * return the full merged config as the body but key the ETag off the
     * persisted delta so it survives the save→reload round-trip.
     *
     * @param array<int, string> $invalidates
     */
    protected function respondWithEtag(mixed $data, int $status = 200, array $invalidates = [], ?string $etag = null, ?array $meta = null): ResponseInterface
    {
        $etag ??= $this->generateEtag($data);
        $headers = ['ETag' => '"' . $etag . '"'];
        if ($invalidates !== []) {
            $headers['X-Invalidates'] = implode(', ', $invalidates);
        }
        return ApiResponse::create($data, $status, $headers, $meta);
    }

    /**
     * Build headers array containing just the X-Invalidates header for a set of tags.
     * Useful when composing responses via ApiResponse::created() / noContent() etc.
     *
     * @param array<int, string> $tags
     * @return array<string, string>
     */
    protected function invalidationHeaders(array $tags): array
    {
        $tags = array_values(array_filter($tags, static fn($t) => is_string($t) && $t !== ''));
        return $tags === [] ? [] : ['X-Invalidates' => implode(', ', $tags)];
    }

    /**
     * Create a response with an X-Invalidates header declaring which client-side
     * caches this mutation should evict. Tags follow `resource:action[:id]` form:
     *
     *   pages:update:/blog/post-1
     *   pages:list
     *   users:create
     *
     * The admin-next client reads this header and emits invalidation events on
     * its pub/sub bus, causing list/detail views to refetch automatically.
     *
     * @param array<int, string> $tags
     */
    protected function respondWithInvalidation(
        mixed $data,
        array $tags,
        int $status = 200,
        array $extraHeaders = [],
    ): ResponseInterface {
        $headers = $extraHeaders;
        if ($tags !== []) {
            $headers['X-Invalidates'] = implode(', ', $tags);
        }
        if ($status === 204) {
            // 204 responses have no body — use a bare Response with headers only.
            $headers['Cache-Control'] = 'no-store, max-age=0';
            return new \Grav\Framework\Psr7\Response(204, $headers);
        }
        return ApiResponse::create($data, $status, $headers);
    }

    /**
     * Build the API base URL for link generation.
     */
    protected function getApiBaseUrl(): string
    {
        $base = $this->config->get('plugins.api.route', '/api');
        $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
        return '/' . trim($base, '/') . '/' . $prefix;
    }

    /**
     * Validate required fields are present in the request body.
     */
    protected function requireFields(array $body, array $fields): void
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($body[$field]) || (is_string($body[$field]) && trim($body[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if ($missing) {
            throw new ValidationException(
                'Missing required fields: ' . implode(', ', $missing),
                array_map(fn($f) => ['field' => $f, 'message' => "The '{$f}' field is required."], $missing)
            );
        }
    }

    /**
     * Fire a Grav event with the given data.
     * Returns the event object so callers can check for modifications.
     */
    protected function fireEvent(string $name, array $data = []): Event
    {
        $event = new Event($data);
        $this->grav->fireEvent($name, $event);
        return $event;
    }

    /**
     * Fire an admin-compatible event alongside the API's own events.
     *
     * Third-party plugins subscribe to onAdmin* events for critical operations
     * (SEO indexing, frontmatter injection, cache busting, etc.). These events
     * are normally only fired by the admin plugin's controllers, so API-driven
     * changes would silently bypass them. This method ensures compatibility by
     * firing the same events with the same data signatures the admin uses.
     */
    protected function issueTokenPair(JwtAuthenticator $jwt, UserInterface $user): ResponseInterface
    {
        return ApiResponse::create($this->buildTokenPairPayload($jwt, $user));
    }

    /**
     * Build the access/refresh token-pair payload for an authenticated user.
     *
     * This is the body `issueTokenPair()` wraps in an ApiResponse, exposed as a
     * raw array so login transports that can't return the response inline — the
     * SSO/OAuth bridge stashes it under a one-time exchange code and replays it
     * later — can reuse the exact same shape the `/auth/token` endpoint returns.
     *
     * @return array<string, mixed>
     */
    protected function buildTokenPairPayload(JwtAuthenticator $jwt, UserInterface $user): array
    {
        $accessToken = $jwt->generateAccessToken($user);
        $refreshToken = $jwt->generateRefreshToken($user);
        $expiresIn = (int) $this->config->get('plugins.api.auth.jwt_expiry', 3600);

        return [
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'user' => $this->buildUserProfile($user),
        ];
    }

    /**
     * The canonical "who am I / what can I do" envelope for a user, shared by
     * every login transport's token-pair response and GET /me so the two never
     * drift. Includes the resolved permission map and the per-account demo_mode
     * block the Admin Next SPA reads to gate writes and show the demo banner.
     *
     * @return array<string, mixed>
     */
    protected function buildUserProfile(UserInterface $user): array
    {
        $isSuperAdmin = $this->isSuperAdmin($user);
        $resolvedAccess = $this->getPermissionResolver()->resolvedMap($user, $isSuperAdmin);

        return [
            'username'    => $user->username,
            'fullname'    => $user->get('fullname'),
            'email'       => $user->get('email'),
            'avatar_url'  => UserSerializer::resolveAvatarUrl($user),
            'super_admin' => $isSuperAdmin,
            'access'      => $resolvedAccess,
            'content_editor' => $user->get('content_editor', ''),
            'demo_mode'   => $this->buildDemoModePayload($user),
        ];
    }

    /**
     * Per-account demo-mode state for the SPA. `enabled` reflects the account's
     * access.api.demo flag; the writable allowlist and reset countdown are only
     * exposed for a demo account (a normal user gets `writable: []`,
     * `seconds_until_reset: null`). `reset_interval` is harmless config metadata,
     * always present so the client needn't make a second call.
     *
     * @return array{enabled: bool, writable: list<string>, reset_interval: int, seconds_until_reset: int|null}
     */
    protected function buildDemoModePayload(UserInterface $user): array
    {
        $enabled = (bool) $user->get('access.api.demo');
        $manager = new DemoManager($this->grav, $this->config);

        return [
            'enabled' => $enabled,
            'writable' => $enabled ? array_values((array) $this->config->get('plugins.api.demo.writable', [])) : [],
            'reset_interval' => $manager->resetIntervalMinutes(),
            'seconds_until_reset' => $enabled ? $manager->secondsUntilReset() : null,
        ];
    }

    /**
     * Shared post-authentication gate for every login transport.
     *
     * A user that has just proven their identity (password verified, or an
     * OAuth provider vouched for them) is run through the same API-access and
     * account-state checks here, then either handed a 2FA challenge or a full
     * token pair. Returns the raw payload array — a 2FA challenge
     * (`requires_2fa` / `challenge_token`) or a token pair — matching the shape
     * `/auth/token` returns, so callers can return it inline (password login)
     * or stash and replay it (SSO exchange). Throws ForbiddenException when the
     * user may not use the API or the account is disabled, firing
     * `onApiUserLoginFailure` with the reason first.
     *
     * Note: this does NOT touch the login rate limiter or fire the
     * `onApiUserLogin` success event — those are transport-specific (the
     * password limiter has no meaning for SSO) and stay with each caller.
     *
     * @return array<string, mixed>
     */
    protected function finalizeAuthenticatedUser(UserInterface $user, ServerRequestInterface $request): array
    {
        // Gate API access AFTER the identity is established, so any onUserLogin
        // handlers (LDAP group→access mapping, etc.) have populated the access
        // matrix. Accepts super-admins, holders of admin.login, and API-only
        // users granted api.access.
        if (
            !$this->isSuperAdmin($user)
            && !$user->authorize('admin.login')
            && !$this->hasPermission($user, 'api.access')
        ) {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $user->username,
                'reason' => 'no_api_access',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new ForbiddenException('API access is not enabled for this user.');
        }

        if ($user->get('state', 'enabled') === 'disabled') {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $user->username,
                'reason' => 'disabled',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new ForbiddenException('This user account is disabled.');
        }

        $jwt = new JwtAuthenticator($this->grav, $this->config);

        if ($this->userRequiresTwoFactor($user)) {
            // Identity was proven — issue a 2FA challenge. The login only counts
            // as complete once the code verifies in /auth/2fa/verify; callers
            // must NOT reset any rate limiter or fire a success event yet.
            $challengeToken = $jwt->generateChallengeToken($user, self::CHALLENGE_2FA, self::CHALLENGE_TTL);

            return [
                'requires_2fa' => true,
                'challenge_token' => $challengeToken,
                'expires_in' => self::CHALLENGE_TTL,
                'token_type' => 'Challenge',
            ];
        }

        return $this->buildTokenPairPayload($jwt, $user);
    }

    /**
     * Whether an account must clear a 2FA challenge before tokens are issued.
     *
     * 2FA support is provided by the Login plugin's TwoFactorAuth helper. We
     * always honor a per-user configured secret: an account that explicitly
     * enabled 2FA must never be silently downgraded to single-factor —
     * including accounts migrated from Grav 1.7, where the master switch was
     * the admin plugin's `plugins.admin.twofa_enabled` (default true), not the
     * login plugin's `plugins.login.twofa_enabled` (default false) the gate
     * previously keyed off (getgrav/grav#4145). The global flags govern whether
     * enrollment is offered, not whether an existing secret is enforced.
     */
    protected function userRequiresTwoFactor(UserInterface $user): bool
    {
        if (!class_exists(TwoFactorAuth::class)) {
            return false;
        }

        return (bool) $user->get('twofa_enabled') && (bool) $user->get('twofa_secret');
    }

    protected function getRequestIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }

    protected function fireAdminEvent(string $name, array $data = []): Event
    {
        // Ensure $grav['page'] is set when firing page-related admin events.
        // In admin-classic this is always set; with flex-objects via API it may not be,
        // causing plugins that read $grav['page'] (SEO Magic, etc.) to get null.
        $page = $data['page'] ?? $data['object'] ?? null;
        if ($page instanceof PageInterface) {
            // Use offsetUnset first to clear any Pimple frozen state, then set.
            unset($this->grav['page']);
            $this->grav['page'] = $page;
        }

        $event = new Event($data);
        $this->grav->fireEvent($name, $event);
        return $event;
    }

    /**
     * JSON-safe debug dump for the API path (admin2#66).
     *
     * `dump($var)` writes into the output stream, which corrupts the JSON
     * response body when called from an `onApi*` hook or a controller. Use this
     * instead: it routes the value into Grav's debugger (Clockwork), where it
     * appears in the Clockwork browser DevTools panel and in admin-next's
     * built-in API Debug panel — without touching the response body. No-op when
     * the debugger is disabled, so it's safe to leave in place.
     *
     * @param mixed  $value Any value — scalars logged as-is, arrays/objects JSON-encoded.
     * @param string $label Short label shown beside the entry.
     */
    protected function debug(mixed $value, string $label = 'api'): void
    {
        $debugger = $this->grav['debugger'] ?? null;
        if ($debugger === null) {
            return;
        }
        $message = is_scalar($value) || $value === null
            ? (string) $value
            : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        // Grav's addMessage() forwards its 2nd argument to Clockwork as the log
        // *level* (not a label), and Clockwork silently drops entries with an
        // unknown level. So fold our label into the message text and log at the
        // standard 'info' level — exactly how Grav's own boot messages register,
        // which guarantees the entry shows in Clockwork and the debug panel.
        $debugger->addMessage('[' . $label . '] ' . $message, 'info');
    }
}
