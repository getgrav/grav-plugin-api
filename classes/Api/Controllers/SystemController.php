<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Backup\Backups;
use Grav\Common\Helpers\LogViewer;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SystemController extends AbstractApiController
{
    public function info(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $plugins = $this->getPluginsInfo();
        $themes = $this->getThemesInfo();

        $data = [
            'grav_version' => GRAV_VERSION,
            'php_version' => PHP_VERSION,
            'php_extensions' => get_loaded_extensions(),
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'environment' => $this->config->get('system.environment') ?? $this->grav['uri']->environment(),
            'plugins' => $plugins,
            'themes' => $themes,
        ];

        return ApiResponse::create($data);
    }

    public function clearCache(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $query = $request->getQueryParams();
        $scope = $query['scope'] ?? 'standard';

        $allowedScopes = ['all', 'standard', 'images', 'assets', 'tmp'];
        if (!in_array($scope, $allowedScopes, true)) {
            throw new ValidationException(
                "Invalid cache scope '{$scope}'. Allowed: " . implode(', ', $allowedScopes),
            );
        }

        $results = $this->grav['cache']->clearCache($scope);

        return ApiResponse::create([
            'scope' => $scope,
            'message' => "Cache cleared successfully (scope: {$scope}).",
            'details' => $results,
        ]);
    }

    public function logs(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $levelFilter = $query['level'] ?? null;

        $logFile = $this->grav['locator']->findResource('log://grav.log');

        if (!$logFile || !file_exists($logFile)) {
            return ApiResponse::paginated(
                data: [],
                total: 0,
                page: $pagination['page'],
                perPage: $pagination['per_page'],
                baseUrl: $this->getApiBaseUrl() . '/system/logs',
            );
        }

        $viewer = new LogViewer();
        $viewer->pattern = LogViewer::PATTERN;

        // Read all log entries
        $allEntries = $viewer->objectTail($logFile, 0, true);
        $entries = [];

        foreach ($allEntries as $entry) {
            $entryData = [
                'date' => $entry['date'] ?? null,
                'level' => $entry['level'] ?? 'DEBUG',
                'message' => $entry['message'] ?? '',
                'context' => $entry['context'] ?? '',
            ];

            // Filter by level if specified
            if ($levelFilter !== null) {
                $filterUpper = strtoupper($levelFilter);
                if (strtoupper($entryData['level']) !== $filterUpper) {
                    continue;
                }
            }

            $entries[] = $entryData;
        }

        // Reverse so newest entries come first
        $entries = array_reverse($entries);
        $total = count($entries);

        // Apply pagination
        $paged = array_slice($entries, $pagination['offset'], $pagination['limit']);

        return ApiResponse::paginated(
            data: $paged,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/system/logs',
        );
    }

    public function backup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $backups = new Backups();
        $result = $backups::backup();

        $filename = basename($result);
        $size = file_exists($result) ? filesize($result) : 0;

        return ApiResponse::created(
            data: [
                'filename' => $filename,
                'path' => $result,
                'size' => $size,
                'date' => date('c'),
            ],
            location: $this->getApiBaseUrl() . '/system/backups',
        );
    }

    public function backups(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $backups = new Backups();
        $list = $backups::getBackups();

        $data = [];
        foreach ($list as $backup) {
            $data[] = [
                'filename' => $backup['filename'] ?? basename($backup['path'] ?? ''),
                'date' => $backup['date'] ?? null,
                'size' => $backup['size'] ?? 0,
            ];
        }

        return ApiResponse::create($data);
    }

    private function getPluginsInfo(): array
    {
        $plugins = [];
        $gpm = $this->grav['plugins'];

        foreach ($gpm as $plugin) {
            $blueprint = $plugin->blueprints();
            $plugins[] = [
                'name' => $blueprint->get('name') ?? $plugin->name,
                'version' => $blueprint->get('version') ?? '0.0.0',
                'enabled' => $this->config->get("plugins.{$plugin->name}.enabled", false),
            ];
        }

        return $plugins;
    }

    private function getThemesInfo(): array
    {
        $themes = [];
        $activeTheme = $this->config->get('system.pages.theme');
        $themesDir = $this->grav['locator']->findResource('themes://');

        if (!$themesDir || !is_dir($themesDir)) {
            return $themes;
        }

        $iterator = new \DirectoryIterator($themesDir);
        foreach ($iterator as $item) {
            if ($item->isDot() || !$item->isDir()) {
                continue;
            }

            $blueprintFile = $item->getPathname() . '/blueprints.yaml';
            if (!file_exists($blueprintFile)) {
                continue;
            }

            $blueprint = \Grav\Common\Yaml::parse(file_get_contents($blueprintFile));
            $themeName = $item->getFilename();

            $themes[] = [
                'name' => $blueprint['name'] ?? $themeName,
                'version' => $blueprint['version'] ?? '0.0.0',
                'active' => $themeName === $activeTheme,
            ];
        }

        return $themes;
    }
}
