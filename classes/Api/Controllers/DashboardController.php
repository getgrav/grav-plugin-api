<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Doctrine\Common\Cache\FilesystemCache;
use Grav\Common\GPM\GPM;
use Grav\Common\GPM\Remote\GravCore;
use Grav\Common\GPM\Remote\Plugins as RemotePlugins;
use Grav\Common\GPM\Remote\Themes as RemoteThemes;
use Grav\Common\HTTP\Response;
use Grav\Common\User\DataUser\User as DataUser;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\FlexBackend;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\ExposureProbe;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\YamlFile;

class DashboardController extends AbstractApiController
{
    use FlexBackend;

    /**
     * GET /dashboard/notifications - Get system notifications.
     */
    public function notifications(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $query = $request->getQueryParams();
        $force = filter_var($query['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = $this->getUser($request);
        $username = $user->get('username');

        // Load cached notifications (v2 schema — see notifications2.md on getgrav.org)
        $cacheFile = $this->grav['locator']->findResource(
            'user://data/notifications/' . md5($username) . '_v2.yaml',
            true,
            true
        );
        $userStatusFile = $this->grav['locator']->findResource(
            'user://data/notifications/' . $username . '.yaml',
            true,
            true
        );

        $notificationsFile = YamlFile::instance($cacheFile);
        $notificationsContent = (array) $notificationsFile->content();
        $userStatusContent = file_exists($userStatusFile)
            ? (array) YamlFile::instance($userStatusFile)->content()
            : [];

        $lastChecked = $notificationsContent['last_checked'] ?? null;
        $notifications = $notificationsContent['data'] ?? [];
        $timeout = $this->grav['config']->get('system.session.timeout', 1800);

        // Refresh from remote if needed
        if ($force || !$lastChecked || empty($notifications) || (time() - $lastChecked > $timeout)) {
            try {
                $body = Response::get('https://getgrav.org/notifications2.json?' . time());
                $rawNotifications = json_decode($body, true);

                if (is_array($rawNotifications)) {
                    // Sort by date descending
                    usort($rawNotifications, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));

                    // Group by location
                    $notifications = [];
                    foreach ($rawNotifications as $notification) {
                        foreach ($notification['location'] ?? [] as $location) {
                            $notifications[$location][] = $notification;
                        }
                    }

                    $notificationsFile->content(['last_checked' => time(), 'data' => $notifications]);
                    $notificationsFile->save();
                }
            } catch (\Exception $e) {
                // Use cached data on failure
            }
        }

        // Let plugins contribute notifications (grouped by location: `top`,
        // `dashboard`, `feed`). Fired after the remote refresh so plugin notices
        // are merged fresh every request (never cached) yet still flow through
        // the dismiss + reappear_after handling below — a plugin-provided `id`
        // is dismissed via the same /notifications/{id}/hide endpoint. This is
        // how a plugin can raise a persistent, dismissible admin banner.
        $event = new Event([
            'notifications' => $notifications,
            'user' => $user,
            'force' => $force,
        ]);
        $this->grav->fireEvent('onApiDashboardNotifications', $event);
        $contributed = $event['notifications'];
        if (is_array($contributed)) {
            $notifications = $contributed;
        }

        // Filter out hidden notifications
        foreach ($notifications as $location => &$list) {
            $list = array_values(array_filter($list, function ($notification) use ($userStatusContent) {
                $hidden = $userStatusContent[$notification['id']] ?? null;
                if ($hidden === null) {
                    return true;
                }

                // Check reappear_after
                if (isset($notification['reappear_after'])) {
                    $now = new \DateTime();
                    $hiddenOn = new \DateTime($hidden);
                    $hiddenOn->modify($notification['reappear_after']);
                    return $now >= $hiddenOn;
                }

                return false;
            }));
        }
        unset($list);

        // Filter by location if requested
        $filter = $query['location'] ?? null;
        if ($filter) {
            $notifications = [$filter => $notifications[$filter] ?? []];
        }

        return ApiResponse::create([
            'notifications' => $notifications,
            'last_checked' => $lastChecked ? date('c', $lastChecked) : null,
        ]);
    }

    /**
     * POST /dashboard/notifications/{id}/hide - Dismiss a notification.
     */
    public function hideNotification(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $id = (string) $this->getRouteParam($request, 'id');
        // Every ID we can show is either a getgrav.org number (`201`) or a
        // plugin slug (`login-lockout`, `api-support.welcome`). Anything else
        // can't match a notification, and storing it would let a caller grow
        // the per-user status file with arbitrary keys.
        if (!self::isValidNotificationId($id)) {
            throw new ValidationException('Invalid notification ID.');
        }

        $user = $this->getUser($request);
        $username = $user->get('username');

        $userStatusFile = $this->grav['locator']->findResource(
            'user://data/notifications/' . $username . '.yaml',
            true,
            true
        );

        $file = YamlFile::instance($userStatusFile);
        $content = (array) $file->content();
        $content[$id] = date('Y-m-d H:i:s');
        $file->content($content);
        $file->save();

        return ApiResponse::noContent();
    }

    /**
     * Letters, digits, `.`, `_` and `-`, starting with a letter or digit, at
     * most 64 characters.
     */
    public static function isValidNotificationId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/', $id);
    }

    /**
     * GET /dashboard/feed - Get getgrav.org news feed as JSON.
     */
    public function feed(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $query = $request->getQueryParams();
        $force = filter_var($query['force'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user = $this->getUser($request);
        $username = $user->get('username');

        $cacheFile = $this->grav['locator']->findResource(
            'user://data/feed/' . md5($username) . '.yaml',
            true,
            true
        );

        $feedFile = YamlFile::instance($cacheFile);
        $feedContent = (array) $feedFile->content();

        $lastChecked = $feedContent['last_checked'] ?? null;
        $feed = $feedContent['data'] ?? [];
        $timeout = $this->grav['config']->get('system.session.timeout', 1800);

        // Refresh from remote if needed
        if ($force || !$lastChecked || empty($feed) || (time() - $lastChecked > $timeout)) {
            try {
                $body = Response::get('https://getgrav.org/blog.atom');
                $xml = simplexml_load_string($body);

                if ($xml) {
                    $feed = [];
                    $count = 0;
                    foreach ($xml->entry as $entry) {
                        if ($count >= 10) break;

                        $feed[] = [
                            'title' => (string) $entry->title,
                            'url' => (string) $entry->link['href'],
                            'date' => (string) $entry->updated,
                            'summary' => (string) ($entry->summary ?? ''),
                        ];
                        $count++;
                    }

                    $feedFile->content(['last_checked' => time(), 'data' => $feed]);
                    $feedFile->save();
                }
            } catch (\Exception $e) {
                // Use cached data on failure
            }
        }

        return ApiResponse::create([
            'feed' => $feed,
            'last_checked' => $lastChecked ? date('c', $lastChecked) : null,
        ]);
    }

    /**
     * GET /dashboard/stats - Dashboard statistics snapshot.
     */
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        // Count pages
        $pages = $this->grav['pages'];
        $pages->enablePages();
        $allPages = $pages->instances();
        $totalPages = 0;
        $publishedPages = 0;

        foreach ($allPages as $page) {
            // Skip the virtual pages-root container (no file on disk); the home
            // page IS a real file-backed page with route '/', and a page
            // carrying `routes.default: ''` is a real page whose route is the
            // empty string. Ask root() rather than testing the route for
            // truthiness, which was leaving such pages out of the count
            // (getgrav/grav-plugin-api#34).
            if ($page->root() || !$page->exists()) {
                continue;
            }
            $totalPages++;
            if ($page->published()) {
                $publishedPages++;
            }
        }

        // Count users via the same Flex-aware path UsersController uses, so the
        // tally matches the Users listing regardless of account storage layout.
        $totalUsers = $this->countUsers();

        // Count plugins
        $plugins = $this->grav['plugins']->all();
        $activePlugins = 0;
        foreach ($plugins as $name => $plugin) {
            if ($this->grav['config']->get("plugins.{$name}.enabled", false)) {
                $activePlugins++;
            }
        }

        // Count themes
        $themes = $this->grav['themes']->all();
        $totalThemes = is_countable($themes) ? count($themes) : 0;

        // Active theme
        $activeTheme = $this->grav['config']->get('system.pages.theme');

        // Available-update counts for the sidebar badges, or null for "unknown,
        // not checked yet". See gpmUpdateCounts(): this never goes to the network.
        $counts = $this->gpmUpdateCounts(is_string($activeTheme) ? $activeTheme : null);
        $pluginUpdates = $counts['plugins'] ?? null;
        $themeUpdates = $counts['themes'] ?? null;
        $gravUpdatable = $counts['grav'] ?? null;
        $activeThemeUpdatable = $counts['active_theme'] ?? null;

        // Count media files. The recursive walk is O(total files) and runs on a
        // dashboard endpoint, so the tally is cached for a few minutes — a
        // slightly stale count is invisible on a dashboard card, while walking
        // a 10k-file library per visit is not.
        // Only `user://media` counts: that is the one directory the Media
        // browser lists, so anything else here reports a number the user cannot
        // reconcile with what they see (`user://images` is a plain assets
        // folder that plugins and themes write to).
        $mediaDir = $this->grav['locator']->findResource('user://media', true);
        $totalMedia = 0;
        if ($mediaDir && is_dir($mediaDir)) {
            $cache = $this->grav['cache'];
            $cacheKey = 'api-dashboard-media-count-' . md5($mediaDir);
            $cached = $cache->fetch($cacheKey);
            if (is_int($cached)) {
                $totalMedia = $cached;
            } else {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    // Skip sidecars, not real media: `.meta.yaml` metadata files, the
                    // per-folder `media_order.yaml`, and hidden dotfiles — the same
                    // files the media listing excludes.
                    $name = $file->getFilename();
                    if (
                        str_starts_with($name, '.')
                        || str_ends_with($name, '.meta.yaml')
                        || $name === 'media_order.yaml'
                    ) {
                        continue;
                    }
                    $totalMedia++;
                }
                $cache->save($cacheKey, $totalMedia, 300);
            }
        }

        // Last backup
        $backupsDir = $this->grav['locator']->findResource('backup://', true);
        $lastBackup = null;
        if ($backupsDir && is_dir($backupsDir)) {
            // Only Grav's own `<name>--<timestamp>.zip` archives, not the exposure
            // probe's sentinel or anything else that happens to be a zip.
            $backups = preg_grep('/--\d+\.zip$/', glob($backupsDir . '/*.zip') ?: []);
            if (!empty($backups)) {
                $latest = max(array_map('filemtime', $backups));
                $lastBackup = date('c', $latest);
            }
        }

        $data = [
            'pages' => [
                'total' => $totalPages,
                'published' => $publishedPages,
            ],
            'users' => [
                'total' => $totalUsers,
            ],
            'plugins' => [
                'total' => count($plugins),
                'active' => $activePlugins,
                'updatable' => $pluginUpdates,
            ],
            'themes' => [
                'total' => $totalThemes,
                'updatable' => $themeUpdates,
                'active_updatable' => $activeThemeUpdatable,
            ],
            'grav' => [
                'updatable' => $gravUpdatable,
            ],
            'media' => [
                'total' => $totalMedia,
            ],
            'theme' => $activeTheme,
            'grav_version' => GRAV_VERSION,
            'php_version' => PHP_VERSION,
            'last_backup' => $lastBackup,
        ];

        return ApiResponse::create($data);
    }

    /**
     * Available-update counts from the repository data GPM already has on disk,
     * or null when there is none to read.
     *
     * `new GPM(false)` is not the network-free lookup it looks like: when a
     * repository file is missing or past its 24 hour lifetime, which includes
     * straight after every cache clear, it downloads plugins.json and
     * themes.json (about 2.5 MB each) inside this request. That was the 1 to 2
     * second dashboard spike. So the cached repository entries are checked
     * first, and if any is missing or expired the counts are reported as
     * unknown; `/gpm/updates` or the scheduler refreshes them and the next call
     * reads them.
     *
     * Even with the files present, counting means decoding both repositories
     * and every installed blueprint, so the result is kept in the Grav cache,
     * keyed on the repository files and the installed packages' blueprints.
     *
     * @return array{plugins: int, themes: int, grav: bool, active_theme: bool}|null
     */
    private function gpmUpdateCounts(?string $activeTheme): ?array
    {
        try {
            $gpmDir = $this->grav['locator']->findResource('cache://gpm', true, true);
            if (!is_string($gpmDir) || !is_dir($gpmDir)) {
                return null;
            }

            $channel = (string) $this->grav['config']->get('system.gpm.releases', 'stable');
            $query = '?v=' . GRAV_VERSION . '&php=' . PHP_VERSION . '&' . $channel . '=1';
            $repositories = new FilesystemCache($gpmDir);
            foreach ([RemotePlugins::class, RemoteThemes::class, GravCore::class] as $class) {
                $url = self::remoteRepositoryUrl($class);
                if ($url === null || !$repositories->contains(md5($url . $query))) {
                    return null;
                }
            }

            $cache = $this->grav['cache'];
            $cacheKey = 'api-dashboard-gpm-counts-' . $this->gpmCountsFingerprint($gpmDir, $query, $activeTheme);
            $cached = $cache->fetch($cacheKey);
            if (is_array($cached) && isset($cached['plugins'], $cached['themes'], $cached['grav'], $cached['active_theme'])) {
                return $cached;
            }

            $counts = self::extractUpdateCounts(new GPM(false), $activeTheme);
            $cache->save($cacheKey, $counts, 86400);

            return $counts;
        } catch (\Throwable $e) {
            // A GPM hiccup must never take down the dashboard.
            $this->grav['log']->warning('[api] Dashboard stats could not read GPM update counts: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The repository URL a GPM remote collection reads, from the class's own
     * default so it cannot drift from core.
     *
     * @param class-string $class
     */
    private static function remoteRepositoryUrl(string $class): ?string
    {
        try {
            $url = (new \ReflectionProperty($class, 'repository'))->getDefaultValue();
        } catch (\ReflectionException) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Stat-only signature of what the update counts depend on: the GPM
     * repository files (their mtime moves on every save), the Grav and PHP
     * versions and release channel baked into the repository query, the active
     * theme, and each installed plugin's and theme's `blueprints.yaml`, which
     * is where its version lives.
     */
    private function gpmCountsFingerprint(string $gpmDir, string $query, ?string $activeTheme): string
    {
        $parts = [$query, (string) $activeTheme];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($gpmDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->isFile()) {
                $parts[] = $file->getPathname() . ':' . $file->getMTime() . ':' . $file->getSize();
            }
        }

        foreach (['plugins://', 'themes://'] as $stream) {
            foreach ((array) $this->grav['locator']->findResources($stream) as $root) {
                foreach (glob($root . '/*/blueprints.yaml') ?: [] as $blueprint) {
                    $parts[] = $blueprint . ':' . (@filemtime($blueprint) ?: 0);
                }
            }
        }
        sort($parts);

        return md5(implode('|', $parts));
    }

    /**
     * Reduce a GPM instance to the available-update counts the dashboard needs:
     * number of updatable plugins, number of updatable themes, whether Grav core
     * itself has an update, and whether the currently active theme specifically
     * has one (so the "Active Theme" card only flags an update for the theme it
     * actually names). Pure and side-effect free so it can be unit-tested against
     * a mocked GPM without booting Grav.
     *
     * @param string|null $activeThemeSlug Slug of the active theme, or null to skip the active-theme check
     * @return array{plugins: int, themes: int, grav: bool, active_theme: bool}
     */
    public static function extractUpdateCounts(GPM $gpm, ?string $activeThemeSlug = null): array
    {
        $updatable = $gpm->getUpdatable();
        $gravInfo = $gpm->getGrav();

        $updatableThemes = is_array($updatable['themes'] ?? null) ? $updatable['themes'] : [];

        return [
            'plugins' => is_countable($updatable['plugins'] ?? null) ? count($updatable['plugins']) : 0,
            'themes' => count($updatableThemes),
            'grav' => $gravInfo ? $gravInfo->isUpdatable() : false,
            'active_theme' => $activeThemeSlug !== null && isset($updatableThemes[$activeThemeSlug]),
        ];
    }

    /**
     * Test multiple file types because front proxies may serve only some extensions
     * themselves. Never probe real submissions, credentials or backup archives.
     */
    public function securityProbe(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $rootUrl = rtrim($this->grav['uri']->rootUrl(true), '/');
        $probes = [];
        $legacy = ['url' => '', 'token' => '', 'available' => false];
        foreach (['user://data', 'backup://', 'tmp://'] as $stream) {
            $directory = $this->grav['locator']->findResource($stream, true, true);
            if (!is_string($directory) || $directory === '') {
                continue;
            }
            $publicPath = ExposureProbe::publicPath($directory, GRAV_WEBROOT);
            // user/ can be symlinked outside the web root while retaining its public
            // URL. Use that configured path when the locator resolves the symlink.
            if ($stream === 'user://data') {
                $userPath = defined('GRAV_USER_PATH') ? GRAV_USER_PATH : 'user';
                if (!str_starts_with($userPath, '/') && !preg_match('/^[a-z]:/i', $userPath)) {
                    $publicPath = ExposureProbe::publicPath(GRAV_WEBROOT . '/' . $userPath . '/data', GRAV_WEBROOT);
                }
            }
            // Relocated backup/tmp storage outside the web root has no direct URL.
            if ($publicPath !== null) {
                $created = ExposureProbe::create($directory, $publicPath, $rootUrl);
                if ($stream === 'user://data') {
                    $legacy = $created[0];
                }
                array_push($probes, ...$created);
            }
        }

        // Keep the original single-probe fields for older Admin2 bundles.
        return ApiResponse::create([
            'url' => $legacy['url'],
            'token' => $legacy['token'],
            'available' => $legacy['available'],
            'probes' => $probes,
        ]);
    }

    /**
     * GET /dashboard/popularity - Page view statistics.
     *
     * Reads from PopularityStore (single-file flat JSON, ISO date keys).
     * On first read after an upgrade from admin-classic, the store imports
     * the legacy four-JSON-file layout transparently.
     */
    public function popularity(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $store = new \Grav\Plugin\Api\Popularity\PopularityStore();
        $daily = $store->getDaily(365);
        $monthly = $store->getMonthly(24);

        $todayKey = date('Y-m-d');
        $thisMonthKey = date('Y-m');

        $todayViews = (int) ($daily[$todayKey] ?? 0);

        // Sum last 7 days from ISO-keyed daily map
        $weekViews = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $weekViews += (int) ($daily[$day] ?? 0);
        }

        $monthViews = (int) ($monthly[$thisMonthKey] ?? 0);

        // 14-day chart, oldest first
        $chartData = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $chartData[] = [
                'date' => date('M j', strtotime("-{$i} days")),
                'views' => (int) ($daily[$day] ?? 0),
            ];
        }

        $topPages = [];
        foreach ($store->getTopPages(10) as $route => $views) {
            $topPages[] = ['route' => $route, 'views' => (int) $views];
        }

        return ApiResponse::create([
            'summary' => [
                'today' => $todayViews,
                'week' => $weekViews,
                'month' => $monthViews,
            ],
            'chart' => $chartData,
            'top_pages' => $topPages,
        ]);
    }

    /**
     * Count user accounts using the same backend resolution as UsersController.
     *
     * When the Flex accounts backend is active (the default, and required for
     * custom/nested storage where files live at user/accounts/<name>/user.yaml)
     * we count via the directory index. Otherwise we fall back to scanning the
     * flat top-level account YAML files.
     */
    private function countUsers(): int
    {
        $directory = $this->getFlexDirectory('user-accounts');
        if ($directory) {
            // Mirror UsersController::indexViaFlex — Flex indexes every file in
            // user/accounts/ without an extension filter, so drop keys that
            // aren't valid usernames or that carry a stored-file extension
            // (revisions-pro/backup strays) before counting.
            $keys = array_filter(
                $directory->getIndex()->getKeys(),
                static fn($k) => is_string($k)
                    && DataUser::isValidUsername($k)
                    && !preg_match('/\.(ya?ml|json)(\.|$)/i', $k),
            );

            return count($keys);
        }

        // Flat-file fallback: top-level account YAML files.
        $accountDir = $this->grav['locator']->findResource('account://', true)
            ?: $this->grav['locator']->findResource('user://accounts', true);
        if ($accountDir && is_dir($accountDir)) {
            return count(glob($accountDir . '/*.yaml') ?: []);
        }

        return 0;
    }
}
