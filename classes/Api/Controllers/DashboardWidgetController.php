<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\DashboardLayoutResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Dashboard widget customization endpoints.
 *
 * Backs admin-next's per-user / per-site customizable dashboard. The merged
 * widget list combines a built-in core registry, plugin contributions via
 * `onApiDashboardWidgets`, the site default layout (super-admin), and the
 * current user's overrides. Site-hidden widgets are not exposed to users.
 */
class DashboardWidgetController extends AbstractApiController
{
    /**
     * GET /dashboard/widgets — Resolved widget list + layouts for the current user.
     */
    public function widgets(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $resolver = $this->getResolver();
        $isSuperAdmin = $this->isSuperAdmin($user);

        return ApiResponse::create($resolver->resolve($user, $isSuperAdmin));
    }

    /**
     * PATCH /dashboard/layout — Save the current user's dashboard layout.
     */
    public function saveUserLayout(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $body = $this->getRequestBody($request);
        if (!is_array($body)) {
            throw new ValidationException('Request body must be a JSON object.');
        }

        $user = $this->getUser($request);
        $resolver = $this->getResolver();
        $resolver->saveUserLayout($user, $body);

        return ApiResponse::create($resolver->resolve($user, $this->isSuperAdmin($user)));
    }

    /**
     * PATCH /dashboard/site-layout — Save the site-wide default dashboard layout.
     *
     * Super-admin only. Widgets marked invisible here are hidden for all users
     * and cannot be re-enabled per-user.
     */
    public function saveSiteLayout(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->getUser($request);
        if (!$this->isSuperAdmin($user)) {
            throw new ForbiddenException('Only super-admins can edit the site dashboard layout.');
        }

        $body = $this->getRequestBody($request);
        if (!is_array($body)) {
            throw new ValidationException('Request body must be a JSON object.');
        }

        $resolver = $this->getResolver();
        $resolver->saveSiteLayout($body);

        return ApiResponse::create($resolver->resolve($user, true));
    }

    private function getResolver(): DashboardLayoutResolver
    {
        return new DashboardLayoutResolver(
            $this->grav,
            new PermissionResolver($this->grav['permissions']),
        );
    }
}
