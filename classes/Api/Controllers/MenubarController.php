<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Menubar API — lets plugins register toolbar items with executable actions.
 *
 * Plugins listen for `onApiMenubarItems` to register items and
 * `onApiMenubarAction` to handle action execution.
 *
 * Item format:
 *   [
 *     'id'      => 'warm-cache',          // unique identifier
 *     'plugin'  => 'warm-cache',          // owning plugin slug
 *     'label'   => 'Warm Cache',          // tooltip / display name
 *     'icon'    => 'fa-tachometer',       // FA icon class
 *     'action'  => 'warm',               // action key for POST
 *     'confirm' => 'Warm the cache?',     // optional confirmation prompt
 *   ]
 */
class MenubarController extends AbstractApiController
{
    /**
     * GET /menubar/items — Collect menu items from plugins.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $event = new Event(['items' => [], 'user' => $this->getUser($request)]);
        $this->grav->fireEvent('onApiMenubarItems', $event);

        return ApiResponse::create($event['items']);
    }

    /**
     * POST /menubar/actions/{plugin}/{action} — Execute a plugin action.
     */
    public function executeAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $plugin = $this->getRouteParam($request, 'plugin');
        $action = $this->getRouteParam($request, 'action');
        $body = $this->getRequestBody($request);

        $event = new Event([
            'plugin' => $plugin,
            'action' => $action,
            'body' => $body,
            'user' => $this->getUser($request),
            'result' => [
                'status' => 'error',
                'message' => "No handler registered for action '{$plugin}/{$action}'.",
            ],
        ]);

        $this->grav->fireEvent('onApiMenubarAction', $event);

        $result = $event['result'];
        $status = ($result['status'] ?? 'error') === 'success' ? 200 : 400;

        return ApiResponse::create($result, $status);
    }
}
