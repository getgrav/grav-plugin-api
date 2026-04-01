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
            if (!$page->route() || $page->route() === '/') {
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

        // Active theme
        $activeTheme = $this->grav['config']->get('system.pages.theme');

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
     * Returns daily, monthly, and per-page totals from the popularity log files
     * written by the admin plugin's Popularity tracker.
     */
    public function popularity(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $logDir = $this->grav['locator']->findResource('log://popularity', true);

        $daily = [];
        $monthly = [];
        $totals = [];
        $visitors = [];

        if ($logDir && is_dir($logDir)) {
            $dailyFile = $logDir . '/daily.json';
            if (file_exists($dailyFile)) {
                $daily = json_decode(file_get_contents($dailyFile), true) ?: [];
            }

            $monthlyFile = $logDir . '/monthly.json';
            if (file_exists($monthlyFile)) {
                $monthly = json_decode(file_get_contents($monthlyFile), true) ?: [];
            }

            $totalsFile = $logDir . '/totals.json';
            if (file_exists($totalsFile)) {
                $totals = json_decode(file_get_contents($totalsFile), true) ?: [];
            }

            $visitorsFile = $logDir . '/visitors.json';
            if (file_exists($visitorsFile)) {
                $visitors = json_decode(file_get_contents($visitorsFile), true) ?: [];
            }
        }

        // Calculate summary stats
        $today = date('d-m-Y');
        $thisWeekStart = date('d-m-Y', strtotime('monday this week'));
        $thisMonth = date('m-Y');

        $todayViews = $daily[$today] ?? 0;

        // Sum last 7 days
        $weekViews = 0;
        for ($i = 0; $i < 7; $i++) {
            $day = date('d-m-Y', strtotime("-{$i} days"));
            $weekViews += $daily[$day] ?? 0;
        }

        $monthViews = $monthly[$thisMonth] ?? 0;

        // Sort daily by date for chart (last 14 days)
        $chartData = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('d-m-Y', strtotime("-{$i} days"));
            $label = date('M j', strtotime("-{$i} days"));
            $chartData[] = [
                'date' => $label,
                'views' => $daily[$day] ?? 0,
            ];
        }

        // Top pages (sorted by views descending, top 10)
        arsort($totals);
        $topPages = [];
        $count = 0;
        foreach ($totals as $route => $views) {
            $topPages[] = ['route' => $route, 'views' => $views];
            if (++$count >= 10) {
                break;
            }
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
