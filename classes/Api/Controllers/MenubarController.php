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
 *     'id'        => 'warm-cache',          // unique identifier
 *     'plugin'    => 'warm-cache',          // owning plugin slug
 *     'label'     => 'Warm Cache',          // tooltip / display name
 *     'icon'      => 'fa-tachometer',       // FA icon class
 *     'action'    => 'warm',                // action key for POST
 *     'confirm'   => 'Warm the cache?',     // optional confirmation prompt
 *     'authorize' => 'api.some.permission', // optional — string or array (any-of)
 *   ]
 *
 * `authorize` follows the same string-or-array semantics as the sidebar API.
 * Items without `authorize` are visible to every authenticated user.
 */
class MenubarController extends AbstractApiController
{
    /**
     * GET /menubar/items — Collect menu items from plugins, filtered by the
     * current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $event = new Event(['items' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiMenubarItems', $event);

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

        return ApiResponse::create($filtered);
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

        $sentinel = "__no_handler_{$plugin}_{$action}__";
        $event = new Event([
            'plugin' => $plugin,
            'action' => $action,
            'body' => $body,
            'user' => $this->getUser($request),
            'result' => [
                'status' => 'error',
                'message' => $sentinel,
            ],
        ]);

        $this->grav->fireEvent('onApiMenubarAction', $event);

        $result = $event['result'];

        // Distinguish "no plugin registered for this action" from a handler
        // that ran and reported a domain-level failure (e.g. auth error from
        // Cloudflare). The former is a 404; the latter is a successful API
        // call that the client will toast as an error based on result.status.
        if (($result['message'] ?? null) === $sentinel) {
            throw new NotFoundException("No handler registered for action '{$plugin}/{$action}'.");
        }

        return ApiResponse::create($result, 200);
    }
}
