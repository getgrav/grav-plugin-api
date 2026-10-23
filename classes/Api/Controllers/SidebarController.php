<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Interfaces\UserInterface;
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
 *     'id'        => 'license-manager',      // unique identifier
 *     'plugin'    => 'license-manager',      // owning plugin slug
 *     'label'     => 'License Manager',      // display name
 *     'icon'      => 'fa-key',              // FA icon class
 *     'route'     => '/plugin/license-manager', // admin-next route
 *     'priority'  => 0,                      // sort order (higher = earlier)
 *     'badge'     => null,                   // optional static badge text/count
 *     'badgeEndpoint' => '/my-plugin/badge', // optional — API path returning { count: N }, refreshed live
 *     'authorize' => 'api.some.permission',  // optional — single permission, or array for any-of
 *     'children'  => [                       // optional — shortcuts a user can show under the item
 *       ['id' => 'orders', 'label' => 'Orders', 'route' => '/plugin/kahunacart#/orders', 'default' => true, 'icon' => 'receipt', 'authorize' => '…'],
 *     ],
 *   ]
 *
 * Each user picks which `children` show under the item, when they arrange the
 * sidebar or from the plugin's own page (window.__GRAV_SIDEBAR); until they
 * do, the ones marked `default` show. A child needs an `id` (letters, digits,
 * `_` and `-`, unique within the item), a `label` and a `route`; `icon` and
 * `authorize` work as on the item itself.
 *
 * When `badgeEndpoint` is set, admin-next fetches it on load and re-fetches on
 * content/config/plugin/theme changes; a plugin can also push an update live by
 * dispatching `grav:sidebar:badge` ({ id, count }). The live count overrides the
 * static `badge`.
 *
 * `authorize` accepts either a string or an array of permissions. An array is
 * treated as an any-of test, matching admin-classic's nav-quick-tray template.
 * Items without `authorize` are shown to every authenticated user (anyone past
 * the api.access gate).
 */
class SidebarController extends AbstractApiController
{
    use TranslatesAdminLabels;

    /**
     * GET /sidebar/items — Collect sidebar items from plugins, filtered by
     * the current user's permissions.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create($this->itemsData($request));
    }

    /**
     * The payload of GET /sidebar/items, shared with GET /admin-next/boot.
     *
     * @return list<array<string, mixed>>
     */
    public function itemsData(ServerRequestInterface $request): array
    {
        $this->requirePermission($request, 'api.access');
        $this->primeAdminLanguages($request);

        $user = $this->getUser($request);
        $event = new Event(['items' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiSidebarItems', $event);
        $filtered = [];
        foreach ($event['items'] as $item) {
            if (!$this->userPassesAuthorize($user, $item['authorize'] ?? null, $request)) {
                continue;
            }
            // Strip the authorize field — it's a server-side annotation, not client data
            unset($item['authorize']);
            if (isset($item['label']) && is_string($item['label'])) {
                $item['label'] = $this->translateLabel($item['label']);
            }
            if (array_key_exists('children', $item)) {
                $item['children'] = $this->children($item['children'], $user, $request);
                if ($item['children'] === []) {
                    unset($item['children']);
                }
            }
            $filtered[] = $item;
        }

        usort($filtered, fn($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));

        return $filtered;
    }

    /**
     * The children of an item this user may open, with only the keys the
     * sidebar draws.
     *
     * @return list<array{id: string, label: string, route: string, default?: true, icon?: string}>
     */
    private function children(mixed $children, UserInterface $user, ServerRequestInterface $request): array
    {
        $out = [];
        $seen = [];
        foreach (is_array($children) ? $children : [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $id = $child['id'] ?? null;
            $label = $child['label'] ?? null;
            $route = $child['route'] ?? null;
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,48}$/', $id) || isset($seen[$id])
                || !is_string($label) || $label === '' || !is_string($route) || $route === '') {
                continue;
            }
            if (!$this->userPassesAuthorize($user, $child['authorize'] ?? null, $request)) {
                continue;
            }
            $seen[$id] = true;
            $entry = ['id' => $id, 'label' => $this->translateLabel($label), 'route' => $route];
            if (($child['default'] ?? false) === true) {
                $entry['default'] = true;
            }
            if (isset($child['icon']) && is_string($child['icon']) && $child['icon'] !== '') {
                $entry['icon'] = $child['icon'];
            }
            $out[] = $entry;
        }

        return $out;
    }
}
