<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

/**
 * Settings API — lets plugins register admin-next settings panels that
 * render as sections inside the Settings page, instead of as standalone
 * sidebar entries via the plugin-page mechanism.
 *
 * Plugins listen for `onApiAdminSettingsPanels` to register panels.
 *
 * Panel format (same shape as plugin-page definitions, blueprint mode only):
 *   [
 *     'id'            => 'login-settings',          // unique identifier
 *     'plugin'        => 'api',                     // plugin owning the blueprint
 *     'label'         => 'Login & Security',        // card title
 *     'description'   => 'Authentication …',        // optional sub-label
 *     'icon'          => 'fa-shield-alt',           // optional FA icon
 *     'blueprint'     => 'login-settings',          // blueprint file name
 *     'data_endpoint' => '/login-settings/data',    // GET endpoint
 *     'save_endpoint' => '/login-settings/save',    // PATCH endpoint
 *     'priority'      => 0,                         // sort order (higher = earlier)
 *   ]
 *
 * Panels are gated by the registering plugin — the user is passed in the
 * event so listeners can skip adding the panel when permissions aren't met.
 */
class SettingsController extends AbstractApiController
{
    /**
     * GET /settings/panels — Collect admin-next settings panels from plugins.
     */
    public function panels(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $event = new Event(['panels' => [], 'user' => $this->getUser($request)]);
        $this->grav->fireEvent('onApiAdminSettingsPanels', $event);

        $panels = $event['panels'] ?? [];
        // Sort by priority descending (higher priority first), preserving
        // insertion order among equal-priority panels.
        usort($panels, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return ApiResponse::create($panels);
    }
}
