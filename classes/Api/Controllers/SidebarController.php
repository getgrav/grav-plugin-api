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
 *     'id'       => 'license-manager',      // unique identifier
 *     'plugin'   => 'license-manager',      // owning plugin slug
 *     'label'    => 'License Manager',      // display name
 *     'icon'     => 'fa-key',              // FA icon class
 *     'route'    => '/plugin/license-manager', // admin-next route
 *     'priority' => 0,                      // sort order (higher = earlier)
 *     'badge'    => null,                   // optional badge text/count
 *   ]
 */
class SidebarController extends AbstractApiController
{
    /**
     * GET /sidebar/items — Collect sidebar items from plugins.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $event = new Event(['items' => [], 'user' => $this->getUser($request)]);
        $this->grav->fireEvent('onApiSidebarItems', $event);

        return ApiResponse::create($event['items']);
    }
}
