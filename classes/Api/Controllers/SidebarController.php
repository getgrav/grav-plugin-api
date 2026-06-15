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
 *     'badge'     => null,                   // optional static badge text/count
 *     'badgeEndpoint' => '/my-plugin/badge', // optional — API path returning { count: N }, refreshed live
 *     'authorize' => 'api.some.permission',  // optional — single permission, or array for any-of
 *   ]
 *
 * When `badgeEndpoint` is set, admin-next fetches it on load and re-fetches on
 * content/config/plugin/theme changes; a plugin can also push an update live by
 * dispatching `grav:sidebar:badge` ({ id, count }). The live count overrides the
 * static `badge`.
 *
 * `authorize` accepts either a string or an array of permissions. An array is
 * treated as an any-of test, matching admin-classic's nav-quick-tray template.
 * Items without `authorize` are shown to every authenticated user (anyone past
 * the api.access gate).
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
            if (!$this->userPassesAuthorize($user, $item['authorize'] ?? null, $isSuperAdmin)) {
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
