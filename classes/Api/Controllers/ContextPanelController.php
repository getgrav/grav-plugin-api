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
 *   ]
 */
class ContextPanelController extends AbstractApiController
{
    /**
     * GET /context-panels — Collect context panel registrations from plugins.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $event = new Event(['panels' => [], 'user' => $this->getUser($request)]);
        $this->grav->fireEvent('onApiContextPanels', $event);

        return ApiResponse::create($event['panels']);
    }
}
