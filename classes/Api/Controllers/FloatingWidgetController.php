<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Floating Widgets API — lets plugins register persistent UI widgets
 * (e.g. chat assistants, notification panels) in the admin-next shell.
 *
 * Plugins listen for `onApiFloatingWidgets` to register widgets.
 *
 * Widget format:
 *   [
 *     'id'       => 'ai-pro-chat',        // unique identifier
 *     'plugin'   => 'ai-pro',             // owning plugin slug
 *     'label'    => 'AI Assistant',        // tooltip / display name
 *     'icon'     => 'bot',                // Lucide icon name
 *     'priority' => 10,                    // sort order (higher = earlier)
 *   ]
 */
class FloatingWidgetController extends AbstractApiController
{
    /**
     * GET /floating-widgets — Collect floating widget registrations from plugins.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $event = new Event(['widgets' => [], 'user' => $this->getUser($request)]);
        $this->grav->fireEvent('onApiFloatingWidgets', $event);

        return ApiResponse::create($event['widgets']);
    }
}
