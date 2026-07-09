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
 *     'autoLoad'  => true,                   // optional — load the widget script eagerly
 *     'routes'    => ['/users'],             // optional — scope autoLoad to admin routes
 *     'authorize' => 'api.some.permission', // optional — string or array (any-of)
 *   ]
 *
 * `authorize` follows the same string-or-array semantics as the sidebar /
 * menubar APIs. Widgets without `authorize` are visible to every authenticated
 * user.
 *
 * `routes` (getgrav/grav-plugin-admin2#116) lets an autoloading widget declare
 * the admin-internal SPA routes it applies to (e.g. `/users`, `/pages`), so
 * Admin2 only loads its script on those routes instead of everywhere. It scopes
 * script loading only — it is NOT a permission boundary; `authorize` remains the
 * security check. Omitting `routes` keeps the current load-everywhere behaviour.
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
            // Normalize any declared route contexts to a clean string list so the
            // client can scope autoloading (#116). Drop the key entirely when a
            // plugin supplied no usable routes, preserving load-everywhere.
            if (array_key_exists('routes', $widget)) {
                $routes = $this->sanitizeRoutes($widget['routes']);
                if ($routes === []) {
                    unset($widget['routes']);
                } else {
                    $widget['routes'] = $routes;
                }
            }
            $filtered[] = $widget;
        }

        return ApiResponse::create($filtered);
    }

    /**
     * Normalize plugin-declared widget route contexts into a de-duplicated list
     * of admin-internal SPA paths (e.g. `/users`, `/plugin/my-plugin`).
     *
     * Each entry is coerced to a leading-slash, trailing-slash-free string; the
     * bare root stays `/`. Non-string and empty entries are dropped. These are
     * matched against Admin2's own router state, never a browser pathname, so
     * they carry no admin-prefix and are not a security boundary.
     *
     * @param mixed $routes
     * @return array<int, string>
     */
    private function sanitizeRoutes($routes): array
    {
        if (!is_array($routes)) {
            return [];
        }

        $clean = [];
        foreach ($routes as $route) {
            if (!is_string($route)) {
                continue;
            }
            $route = trim($route);
            if ($route === '') {
                continue;
            }
            if ($route[0] !== '/') {
                $route = '/' . $route;
            }
            if ($route !== '/') {
                $route = '/' . trim($route, '/');
            }
            if (!in_array($route, $clean, true)) {
                $clean[] = $route;
            }
        }

        return $clean;
    }
}
