<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Backup\Backups;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SystemController extends AbstractApiController
{
    public function environments(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $currentEnv = $this->grav['uri']->environment();
        $requestedEnv = $request->getHeaderLine('X-Grav-Environment') ?: $currentEnv;

        $environments = [
            [
                'name' => 'default',
                'active' => !$this->hasEnvironmentOverrides(),
            ],
        ];

        // Scan user/env/ for environment-specific directories
        $envDir = $this->grav['locator']->findResource('user://env', true);
        if ($envDir && is_dir($envDir)) {
            foreach (new \DirectoryIterator($envDir) as $item) {
                if ($item->isDot() || !$item->isDir()) {
                    continue;
                }
                $environments[] = [
                    'name' => $item->getFilename(),
                    'active' => $item->getFilename() === $requestedEnv,
                ];
            }
        }

        return ApiResponse::create([
            'current' => $requestedEnv,
            'environments' => $environments,
        ]);
    }

    private function hasEnvironmentOverrides(): bool
    {
        $envDir = $this->grav['locator']->findResource('user://env', true);
        return $envDir && is_dir($envDir) && (new \FilesystemIterator($envDir))->valid();
    }

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
            return ApiResponse::paginated([], 0, $pagination['page'], $pagination['per_page'], $this->getApiBaseUrl() . '/system/logs');
        }

        $content = file_get_contents($logFile);
        $lines = explode("\n", $content);
        $entries = [];

        foreach ($lines as $line) {
            if ($line === '' || $line[0] !== '[') {
                continue;
            }

            // Extract date
            $closeBracket = strpos($line, ']');
            if ($closeBracket === false) {
                continue;
            }
            $date = substr($line, 1, $closeBracket - 1);

            // Extract logger.LEVEL: message
            $rest = ltrim(substr($line, $closeBracket + 1));
            $colonPos = strpos($rest, ':');
            if ($colonPos === false) {
                continue;
            }

            $loggerLevel = substr($rest, 0, $colonPos);
            $dotPos = strpos($loggerLevel, '.');
            if ($dotPos === false) {
                continue;
            }

            $logger = substr($loggerLevel, 0, $dotPos);
            $level = strtoupper(substr($loggerLevel, $dotPos + 1));
            $message = trim(substr($rest, $colonPos + 1));

            // Strip trailing [] []
            $message = preg_replace('/\s*\[\]\s*\[\]\s*$/', '', $message);

            if ($levelFilter !== null && $level !== strtoupper($levelFilter)) {
                continue;
            }

            $entries[] = [
                'date' => $date,
                'logger' => $logger,
                'level' => $level,
                'message' => $message,
            ];
        }

        $entries = array_reverse($entries);
        $total = count($entries);
        $paged = array_slice($entries, $pagination['offset'], $pagination['limit']);

        return ApiResponse::paginated($paged, $total, $pagination['page'], $pagination['per_page'], $this->getApiBaseUrl() . '/system/logs');
    }

    public function backup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        // Ensure backup directory is initialized
        $backups = $this->grav['backups'] ?? new Backups();
        if (method_exists($backups, 'init')) {
            $backups->init();
        }

        $result = Backups::backup();

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

        $list = Backups::getAvailableBackups(true);

        $data = [];
        foreach ($list as $backup) {
            $data[] = [
                'filename' => basename($backup['path'] ?? ''),
                'date' => $backup['date'] ?? null,
                'size' => $backup['size'] ?? 0,
            ];
        }

        return ApiResponse::create($data);
    }

    /**
     * GET /translations/{lang} - Get all translation strings for a language.
     *
     * Returns a flat key-value object of all translation strings for efficient
     * client-side caching. Optionally filter by prefix (e.g., ?prefix=PLUGIN_ADMIN).
     */
    public function translations(ServerRequestInterface $request): ResponseInterface
    {
        // No auth required — translation strings are not sensitive

        $lang = $this->getRouteParam($request, 'lang');
        $prefix = $request->getQueryParams()['prefix'] ?? null;

        /** @var \Grav\Common\Language\Language $language */
        $language = $this->grav['language'];

        // Validate language code
        $available = $language->getLanguages();
        if (!empty($available) && !in_array($lang, $available, true)) {
            // Fall back to default language if requested one isn't available
            $lang = $language->getDefault() ?: 'en';
        }

        /** @var \Grav\Common\Config\Languages $languages */
        $languages = $this->grav['languages'];

        try {
            $translations = $languages->flattenByLang($lang);
        } catch (\Throwable) {
            $translations = [];
        }

        // Filter by prefix if requested
        if ($prefix && is_array($translations)) {
            $prefixLower = strtolower($prefix) . '.';
            $translations = array_filter(
                $translations,
                fn($key) => str_starts_with(strtolower($key), $prefixLower),
                ARRAY_FILTER_USE_KEY
            );
        }

        // Include a checksum for cache invalidation
        $checksum = md5(json_encode($translations));

        return ApiResponse::create([
            'lang' => $lang,
            'count' => count($translations),
            'checksum' => $checksum,
            'strings' => $translations,
        ]);
    }

    private function getPluginsInfo(): array
    {
        $plugins = [];
        $gpm = $this->grav['plugins'];

        foreach ($gpm as $plugin) {
            $blueprint = $plugin->getBlueprint();
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
