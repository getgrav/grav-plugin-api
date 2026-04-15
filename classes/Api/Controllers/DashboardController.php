<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\HTTP\Response;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\File\YamlFile;

class DashboardController extends AbstractApiController
{
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

        // Load cached notifications
        $cacheFile = $this->grav['locator']->findResource(
            'user://data/notifications/' . md5($username) . '.yaml',
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
                $body = Response::get('https://getgrav.org/notifications.json?' . time());
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

        $id = $this->getRouteParam($request, 'id');
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
            // Skip the virtual pages-root container (no file on disk); the
            // home page IS a real file-backed page with route '/'.
            if (!$page->route() || !$page->exists()) {
                continue;
            }
            $totalPages++;
            if ($page->published()) {
                $publishedPages++;
            }
        }

        // Count users
        $accountDir = $this->grav['locator']->findResource('account://', true);
        $totalUsers = 0;
        if ($accountDir && is_dir($accountDir)) {
            $totalUsers = count(glob($accountDir . '/*.yaml'));
        }

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

        // Count media files
        $mediaDir = $this->grav['locator']->findResource('user://media', true)
            ?: $this->grav['locator']->findResource('user://images', true);
        $totalMedia = 0;
        if ($mediaDir && is_dir($mediaDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mediaDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $totalMedia++;
                }
            }
        }

        // Last backup
        $backupsDir = $this->grav['locator']->findResource('backup://', true);
        $lastBackup = null;
        if ($backupsDir && is_dir($backupsDir)) {
            $backups = glob($backupsDir . '/*.zip');
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
            ],
            'themes' => [
                'total' => $totalThemes,
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
}
