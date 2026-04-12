<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Sidebar API — lets plugins register navigation items in the admin sidebar.
 *
 * Plugins listen for `onApiSidebarItems` to register items.
 *
 * Item format:
 *   [
 *     'id'        => 'license-manager',      // unique identifier
 *     'plugin'    => 'license-manager',      // owning plugin slug
 *     'label'     => 'License Manager',      // display name
 *     'icon'      => 'fa-key',              // FA icon class
 *     'route'     => '/plugin/license-manager', // admin-next route
 *     'priority'  => 0,                      // sort order (higher = earlier)
 *     'badge'     => null,                   // optional badge text/count
 *     'authorize' => 'api.some.permission',  // optional — filter by user permission
 *   ]
 */
class SidebarController extends AbstractApiController
{
    /**
     * GET /sidebar/items — Collect sidebar items from plugins, filtered by
     * the current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $event = new Event(['items' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiSidebarItems', $event);

        $isSuperAdmin = $this->isSuperAdmin($user);
        $filtered = [];
        foreach ($event['items'] as $item) {
            $authorize = $item['authorize'] ?? null;
            if ($authorize !== null && !$isSuperAdmin && !$this->hasPermission($user, $authorize)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($item['authorize']);
            $filtered[] = $item;
        }

        usort($filtered, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return ApiResponse::create($filtered);
    }
}
