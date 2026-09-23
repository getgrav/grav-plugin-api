<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Context Panels API — lets plugins register slide-in panels
 * triggered by toolbar buttons in the admin-next page editor.
 *
 * Plugins listen for `onApiContextPanels` to register panels.
 *
 * Panel format:
 *   [
 *     'id'            => 'revisions-pro',     // unique identifier
 *     'plugin'        => 'revisions-pro',     // owning plugin slug
 *     'label'         => 'Revision History',  // tooltip / display name
 *     'icon'          => 'history',           // Lucide icon name
 *     'contexts'      => ['pages'],           // where trigger button appears
 *     'priority'      => 10,                  // sort order (higher = earlier)
 *     'width'         => 900,                 // panel width in pixels
 *     'badgeEndpoint' => '/my-plugin/badge',  // optional: returns { count: N }
 *     'authorize'     => 'api.pages.read',    // optional — string or array (any-of)
 *   ]
 *
 * `authorize` follows the same string-or-array semantics as floating widgets
 * and editor toolbar buttons. Panels without it are visible to every
 * authenticated user.
 */
class ContextPanelController extends AbstractApiController
{
    /**
     * GET /context-panels — Collect context panel registrations from plugins,
     * filtered by the current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create($this->itemsData($request));
    }

    /**
     * The payload of GET /context-panels, shared with GET /admin-next/boot.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsData(ServerRequestInterface $request): array
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $event = new Event(['panels' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiContextPanels', $event);

        $filtered = [];
        foreach ((array) $event['panels'] as $panel) {
            if (!is_array($panel) || !$this->userPassesAuthorize($user, $panel['authorize'] ?? null, $request)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($panel['authorize']);
            $filtered[] = $panel;
        }
        // Sort by priority descending (higher first), like /settings/panels;
        // usort is stable, so equal priorities keep registration order.
        usort($filtered, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $filtered;
    }
}
