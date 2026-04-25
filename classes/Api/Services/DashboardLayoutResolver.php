<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\PermissionResolver;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\YamlFile;

/**
 * Resolves the final dashboard widget list for a given user by merging:
 *   1. Built-in core widget registry
 *   2. Plugin-contributed widgets via onApiDashboardWidgets
 *   3. Site layout (super-admin defaults — visibility floor)
 *   4. User layout (per-user overrides — order, size, visible)
 *
 * Site-hidden widgets are stripped before user layout is applied; users can
 * never re-enable a widget the site admin has turned off.
 */
class DashboardLayoutResolver
{
    public const SITE_CONFIG_FILE = 'admin-next.yaml';
    public const VALID_SIZES = ['xs', 'sm', 'md', 'lg', 'xl'];

    public function __construct(
        private readonly Grav $grav,
        private readonly PermissionResolver $permissions,
    ) {}

    /**
     * Built-in core widgets shipped with admin-next.
     *
     * @return array<int, array<string, mixed>>
     */
    public function coreRegistry(): array
    {
        return [
            [
                'id' => 'core.stats',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.STATS',
                'icon' => 'BarChart3',
                'sizes' => ['md', 'lg', 'xl'],
                'defaultSize' => 'xl',
                'authorize' => 'api.system.read',
                'priority' => 100,
            ],
            [
                'id' => 'core.popularity',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.POPULARITY',
                'icon' => 'TrendingUp',
                'sizes' => ['md', 'lg', 'xl'],
                'defaultSize' => 'lg',
                'authorize' => 'api.system.read',
                'priority' => 90,
            ],
            [
                'id' => 'core.system-health',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.SYSTEM_HEALTH',
                'icon' => 'Activity',
                'sizes' => ['sm', 'md', 'lg'],
                'defaultSize' => 'sm',
                'authorize' => 'api.system.read',
                'priority' => 80,
            ],
            [
                'id' => 'core.recent-pages',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.RECENT_PAGES',
                'icon' => 'FileText',
                'sizes' => ['sm', 'md'],
                'defaultSize' => 'sm',
                'authorize' => 'api.pages.read',
                'priority' => 70,
            ],
            [
                'id' => 'core.top-pages',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.TOP_PAGES',
                'icon' => 'Flame',
                'sizes' => ['sm', 'md'],
                'defaultSize' => 'sm',
                'authorize' => 'api.system.read',
                'priority' => 60,
            ],
            [
                'id' => 'core.backups',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.BACKUPS',
                'icon' => 'Archive',
                'sizes' => ['sm', 'md'],
                'defaultSize' => 'sm',
                'authorize' => 'api.system.read',
                'priority' => 50,
            ],
            [
                'id' => 'core.notifications',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.NOTIFICATIONS',
                'icon' => 'Bell',
                'sizes' => ['sm', 'md', 'lg'],
                'defaultSize' => 'md',
                'authorize' => 'api.system.read',
                'priority' => 40,
            ],
            [
                'id' => 'core.news-feed',
                'source' => 'core',
                'label' => 'ADMIN_NEXT.DASHBOARD.WIDGETS.NEWS_FEED',
                'icon' => 'Rss',
                'sizes' => ['sm', 'md', 'lg'],
                'defaultSize' => 'md',
                'authorize' => 'api.system.read',
                'priority' => 30,
            ],
        ];
    }

    /**
     * Collect plugin-contributed widgets via the onApiDashboardWidgets event.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pluginRegistry(UserInterface $user): array
    {
        $event = new Event(['widgets' => [], 'user' => $user]);
        $this->grav->fireEvent('onApiDashboardWidgets', $event);

        $items = [];
        foreach ($event['widgets'] as $widget) {
            if (!is_array($widget) || empty($widget['id'])) {
                continue;
            }
            $widget['source'] = 'plugin';
            $items[] = $widget;
        }
        return $items;
    }

    /**
     * Read the site-wide dashboard layout from user/config/admin-next.yaml.
     *
     * @return array<string, mixed>
     */
    public function siteLayout(): array
    {
        $path = $this->siteConfigFilePath();
        if (!$path || !is_file($path)) {
            return [];
        }
        $content = (array) YamlFile::instance($path)->content();
        $layout = $content['dashboard']['site_layout'] ?? [];
        return is_array($layout) ? $layout : [];
    }

    /**
     * Read this user's saved dashboard layout from their account YAML.
     *
     * @return array<string, mixed>
     */
    public function userLayout(UserInterface $user): array
    {
        $state = $user->get('state');
        if (!is_array($state)) {
            return [];
        }
        $layout = $state['admin_next']['dashboard'] ?? [];
        return is_array($layout) ? $layout : [];
    }

    /**
     * Resolve the final widget list for a user.
     *
     * Returns the merged list with each widget annotated with its effective
     * `visible`, `size`, and `order`, plus a flag indicating whether the
     * widget was hidden by the site admin (in which case the user cannot
     * override).
     *
     * @return array{
     *   widgets: array<int, array<string, mixed>>,
     *   user_layout: array<string, mixed>,
     *   site_layout: array<string, mixed>,
     *   can_edit_site: bool
     * }
     */
    public function resolve(UserInterface $user, bool $isSuperAdmin): array
    {
        $registry = array_merge($this->coreRegistry(), $this->pluginRegistry($user));

        // Permission filter
        $available = [];
        foreach ($registry as $widget) {
            $authorize = $widget['authorize'] ?? null;
            if ($authorize !== null && !$isSuperAdmin && !(bool) $this->permissions->resolve($user, $authorize)) {
                continue;
            }
            $available[$widget['id']] = $widget;
        }

        $siteLayout = $this->siteLayout();
        $userLayout = $this->userLayout($user);

        $siteEntries = $this->indexEntries($siteLayout['widgets'] ?? []);
        $userEntries = $this->indexEntries($userLayout['widgets'] ?? []);

        $merged = [];
        $defaultOrder = 0;
        foreach ($available as $id => $widget) {
            $siteEntry = $siteEntries[$id] ?? null;
            $userEntry = $userEntries[$id] ?? null;

            $siteHidden = $siteEntry !== null && ($siteEntry['visible'] ?? true) === false;

            // If site admin hid this widget, drop it entirely from the user's view.
            if ($siteHidden) {
                continue;
            }

            $size = $userEntry['size'] ?? $siteEntry['size'] ?? $widget['defaultSize'];
            if (!in_array($size, self::VALID_SIZES, true)) {
                $size = $widget['defaultSize'];
            }
            // Coerce to a size the widget supports
            if (!in_array($size, $widget['sizes'], true)) {
                $size = $widget['defaultSize'];
            }

            $visible = $userEntry !== null
                ? (bool) ($userEntry['visible'] ?? true)
                : (bool) ($siteEntry['visible'] ?? true);

            $order = $userEntry['order']
                ?? $siteEntry['order']
                ?? (1000 - (int) ($widget['priority'] ?? 0)) * 10 + $defaultOrder++;

            $widget['visible'] = $visible;
            $widget['size'] = $size;
            $widget['order'] = (int) $order;
            // Strip server-only annotation
            unset($widget['authorize']);
            $merged[] = $widget;
        }

        usort($merged, static fn($a, $b) => $a['order'] <=> $b['order']);

        return [
            'widgets' => $merged,
            'user_layout' => $userLayout,
            'site_layout' => $siteLayout,
            'can_edit_site' => $isSuperAdmin,
        ];
    }

    /**
     * Persist a user's layout to their account YAML under state.admin_next.dashboard.
     *
     * @param array<string, mixed> $layout
     */
    public function saveUserLayout(UserInterface $user, array $layout): void
    {
        $state = $user->get('state');
        if (!is_array($state)) {
            $state = [];
        }
        $state['admin_next'] = is_array($state['admin_next'] ?? null) ? $state['admin_next'] : [];
        $state['admin_next']['dashboard'] = $this->normalizeLayout($layout);
        $user->set('state', $state);
        $user->save();
    }

    /**
     * Persist the site-wide layout to user/config/admin-next.yaml.
     *
     * @param array<string, mixed> $layout
     */
    public function saveSiteLayout(array $layout): void
    {
        $path = $this->siteConfigFilePath(true);
        if (!$path) {
            throw new \RuntimeException('Unable to resolve user/config path for admin-next.yaml.');
        }
        $file = YamlFile::instance($path);
        $content = (array) $file->content();
        $content['dashboard'] = is_array($content['dashboard'] ?? null) ? $content['dashboard'] : [];
        $content['dashboard']['site_layout'] = $this->normalizeLayout($layout);
        $file->content($content);
        $file->save();

        // Make the saved layout visible to the running config in this request
        $config = $this->grav['config'] ?? null;
        if ($config) {
            $config->set('admin-next.dashboard.site_layout', $content['dashboard']['site_layout']);
        }
    }

    /**
     * Normalize a layout payload, dropping unknown keys and bad types.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function normalizeLayout(array $layout): array
    {
        $out = [];
        $preset = $layout['preset'] ?? 'custom';
        if (is_string($preset) && in_array($preset, ['default', 'minimal', 'compact', 'custom'], true)) {
            $out['preset'] = $preset;
        } else {
            $out['preset'] = 'custom';
        }

        $widgets = [];
        foreach ((array) ($layout['widgets'] ?? []) as $entry) {
            if (!is_array($entry) || empty($entry['id']) || !is_string($entry['id'])) {
                continue;
            }
            $size = $entry['size'] ?? null;
            $widgets[] = [
                'id' => $entry['id'],
                'visible' => array_key_exists('visible', $entry) ? (bool) $entry['visible'] : true,
                'size' => is_string($size) && in_array($size, self::VALID_SIZES, true) ? $size : null,
                'order' => isset($entry['order']) ? (int) $entry['order'] : 0,
            ];
        }
        $out['widgets'] = $widgets;
        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private function indexEntries(mixed $entries): array
    {
        if (!is_array($entries)) {
            return [];
        }
        $indexed = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && !empty($entry['id']) && is_string($entry['id'])) {
                $indexed[$entry['id']] = $entry;
            }
        }
        return $indexed;
    }

    /**
     * Resolve the absolute path to user/config/admin-next.yaml.
     */
    private function siteConfigFilePath(bool $createDir = false): ?string
    {
        $locator = $this->grav['locator'] ?? null;
        if ($locator === null) {
            return null;
        }
        $userConfigDir = $locator->findResource('user://config', true) ?: null;
        if ($userConfigDir === null) {
            $userPath = $locator->findResource('user://', true);
            if ($userPath && $createDir) {
                $userConfigDir = $userPath . '/config';
                if (!is_dir($userConfigDir)) {
                    mkdir($userConfigDir, 0775, true);
                }
            }
        }
        if (!$userConfigDir) {
            return null;
        }
        return $userConfigDir . '/' . self::SITE_CONFIG_FILE;
    }
}
