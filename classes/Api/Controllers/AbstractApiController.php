<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
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
     * Check user permission via direct access array lookup.
     */
    protected function hasPermission(UserInterface $user, string $permission): bool
    {
        // Convert dot notation (api.pages.read) to access array path (access.api.pages.read)
        return (bool) $user->get('access.' . $permission);
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
     * Create a response with ETag header.
     */
    protected function respondWithEtag(mixed $data, int $status = 200): ResponseInterface
    {
        $etag = $this->generateEtag($data);
        return ApiResponse::create($data, $status, ['ETag' => '"' . $etag . '"']);
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
}
