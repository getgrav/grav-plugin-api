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
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

abstract class AbstractApiController
{
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
     * @param array $changes  Incoming values (possibly nested), as sent by the client.
     */
    protected function validateChangedFields(array $changes, ?Blueprint $blueprint): void
    {
        if ($blueprint === null || $changes === []) {
            return;
        }

        $schema = $blueprint->schema();
        $errors = [];

        foreach ($blueprint->flattenData($changes) as $name => $value) {
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
        $accessToken = $jwt->generateAccessToken($user);
        $refreshToken = $jwt->generateRefreshToken($user);
        $expiresIn = (int) $this->config->get('plugins.api.auth.jwt_expiry', 3600);

        $isSuperAdmin = $this->isSuperAdmin($user);
        $resolver = $this->getPermissionResolver();
        $resolvedAccess = $resolver->resolvedMap($user, $isSuperAdmin);

        return ApiResponse::create([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type'    => 'Bearer',
            'expires_in'    => $expiresIn,
            'user' => [
                'username'    => $user->username,
                'fullname'    => $user->get('fullname'),
                'email'       => $user->get('email'),
                'avatar_url'  => UserSerializer::resolveAvatarUrl($user),
                'super_admin' => $isSuperAdmin,
                'access'      => $resolvedAccess,
                'content_editor' => $user->get('content_editor', ''),
            ],
        ]);
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
