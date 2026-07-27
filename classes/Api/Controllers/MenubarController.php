<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ForbiddenException;
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
 *     'variant'   => 'primary',             // optional emphasis: default|primary|success|warning|danger
 *     'showLabel' => true,                  // optional — render label text beside the icon
 *     'size'      => 'md',                   // optional — sm (default) | md
 *     'placement' => 'start',               // optional — start (default) | end
 *     'priority'  => 5,                      // optional — ordering within the zone (higher = earlier)
 *     'authorize' => 'api.some.permission', // optional — string or array (any-of)
 *   ]
 *
 * `variant` maps to admin-next theme tokens (never a raw color), so buttons
 * stay readable in light/dark and follow the active theme. `showLabel` + `size`
 * turn a tiny icon into a readable labelled button. All three pass straight
 * through to the client (no allowlist) — an unknown `variant` falls back to the
 * default muted style.
 *
 * `placement` chooses the toolbar zone (admin2#81): `start` (the default) puts
 * the button in the open space on the left of the header, well clear of the
 * destructive Clear Cache action; `end` places it beside the core actions for
 * buttons that genuinely belong with system maintenance. `priority` orders
 * buttons within a zone (higher renders earlier; ties keep registration order).
 * The core actions (View site, Clear Cache) are never plugin-movable.
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
        $filtered = [];
        foreach ($event['items'] as $item) {
            if (!$this->userPassesAuthorize($user, $item['authorize'] ?? null, $request)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($item['authorize']);
            $filtered[] = $item;
        }

        return ApiResponse::create($filtered);
    }

    /**
     * POST /menubar/actions/{plugin}/{action} — Execute a plugin action, after
     * confirming the caller satisfies the `authorize` the owning plugin declared
     * on the matching menubar item.
     */
    public function executeAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $plugin = $this->getRouteParam($request, 'plugin');
        $action = $this->getRouteParam($request, 'action');

        $this->assertActionAuthorized($request, $plugin, $action);

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

    /**
     * Enforce the `authorize` declared on the menubar item that owns this action.
     *
     * items() filtering an item out of the listing only hides the button. Without
     * this check a caller who was never shown it could still run the action by
     * POSTing straight to the route, which on a site running Git Sync, Rsync or
     * Cloudflare means a deploy or a full CDN purge from an account holding
     * nothing but `api.access` (GHSA-8mjx-xjfv-9c88).
     *
     * An action with no matching registered item declares no requirement, so it
     * keeps the baseline `api.access` gate. Several first-party plugins register
     * their handler unconditionally but their item conditionally (rsync only in
     * server mode, seo-magic behind a quicktray toggle), and their admin-next
     * field widgets call this route directly. Failing closed there would break
     * them; those plugins are expected to gate inside their own handler.
     */
    private function assertActionAuthorized(ServerRequestInterface $request, string $plugin, string $action): void
    {
        $user = $this->getUser($request);
        $event = new Event(['items' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiMenubarItems', $event);

        foreach ($event['items'] as $item) {
            if (($item['plugin'] ?? null) !== $plugin || ($item['action'] ?? null) !== $action) {
                continue;
            }
            if (!$this->userPassesAuthorize($user, $item['authorize'] ?? null, $request)) {
                throw new ForbiddenException(
                    "Missing required permission to execute '{$plugin}/{$action}'."
                );
            }
            break;
        }
    }
}
