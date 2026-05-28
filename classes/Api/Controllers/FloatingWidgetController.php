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
 *     'id'        => 'ai-pro-chat',         // unique identifier
 *     'plugin'    => 'ai-pro',              // owning plugin slug
 *     'label'     => 'AI Assistant',         // tooltip / display name
 *     'icon'      => 'bot',                 // Lucide icon name
 *     'priority'  => 10,                     // sort order (higher = earlier)
 *     'authorize' => 'api.some.permission', // optional — string or array (any-of)
 *   ]
 *
 * `authorize` follows the same string-or-array semantics as the sidebar /
 * menubar APIs. Widgets without `authorize` are visible to every authenticated
 * user.
 */
class FloatingWidgetController extends AbstractApiController
{
    /**
     * GET /floating-widgets — Collect floating widget registrations from
     * plugins, filtered by the current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $event = new Event(['widgets' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiFloatingWidgets', $event);

        $isSuperAdmin = $this->isSuperAdmin($user);
        $filtered = [];
        foreach ($event['widgets'] as $widget) {
            if (!$this->userPassesAuthorize($user, $widget['authorize'] ?? null, $isSuperAdmin)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($widget['authorize']);
            $filtered[] = $widget;
        }

        return ApiResponse::create($filtered);
    }
}
