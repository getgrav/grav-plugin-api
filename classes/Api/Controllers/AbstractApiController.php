<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Api\Response\ApiResponse;
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
     * Check if user is a super admin via direct access array lookup.
     * Grav's authorize() requires admin context, so we check directly.
     */
    protected function isSuperAdmin(UserInterface $user): bool
    {
        return (bool) $user->get('access.admin.super');
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
     * Get route parameters captured by FastRoute.
     */
    protected function getRouteParam(ServerRequestInterface $request, string $name): ?string
    {
        $params = $request->getAttribute('route_params', []);
        return $params[$name] ?? null;
    }

    /**
     * Get pagination parameters from query string.
     */
    protected function getPagination(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();
        $defaultPerPage = $this->config->get('plugins.api.pagination.default_per_page', 20);
        $maxPerPage = $this->config->get('plugins.api.pagination.max_per_page', 100);

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
        if ($ifMatch && trim($ifMatch, '"') !== $currentHash) {
            throw new \Grav\Plugin\Api\Exceptions\ConflictException(
                'The resource has been modified since you last retrieved it. Please fetch the latest version and try again.'
            );
        }
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
     * @param array<int, string> $invalidates
     */
    protected function respondWithEtag(mixed $data, int $status = 200, array $invalidates = []): ResponseInterface
    {
        $etag = $this->generateEtag($data);
        $headers = ['ETag' => '"' . $etag . '"'];
        if ($invalidates !== []) {
            $headers['X-Invalidates'] = implode(', ', $invalidates);
        }
        return ApiResponse::create($data, $status, $headers);
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
}
