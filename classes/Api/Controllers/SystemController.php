<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Backup\Backups;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\DisabledPluginLangIndex;
use Grav\Plugin\Api\Services\EnvironmentService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SystemController extends AbstractApiController
{
    /**
     * GET /system/environments — list writable environment targets.
     *
     * Response shape:
     *   {
     *     detected: "host.example",     // what Grav inferred from the URL
     *     environments: [
     *       { name: "",      label: "Default", exists: true, hasOverrides: true|false },
     *       { name: "staging", exists: true, hasOverrides: true }
     *     ]
     *   }
     *
     * `name: ""` represents the base user/config target. Any other entry is an
     * existing user/env/<name>/ folder that can be selected as a write target.
     * Legacy user/<host>/config/ layouts (Grav 1.6 fallback) are included too.
     */
    public function environments(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $envService = new EnvironmentService($this->grav);
        $list = [[
            'name' => '',
            'label' => 'Default',
            'exists' => true,
            'hasOverrides' => false,
        ]];

        foreach ($envService->listEnvironments() as $name) {
            $list[] = [
                'name' => $name,
                'label' => $name,
                'exists' => true,
                'hasOverrides' => $envService->envHasOverrides($name),
            ];
        }

        return ApiResponse::create([
            'detected' => $this->grav['uri']->environment(),
            'environments' => $list,
        ]);
    }

    /**
     * POST /system/environments — create a new env folder.
     *
     * Body: { "name": "staging.foo.com" }
     * Creates user/env/<name>/config/ (and user/env/ if missing).
     */
    public function createEnvironment(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $body = $this->getRequestBody($request);
        $name = trim((string)($body['name'] ?? ''));

        $envService = new EnvironmentService($this->grav);
        try {
            $envService->createEnvironment($name);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage());
        }

        return ApiResponse::create([
            'name' => $name,
            'label' => $name,
            'exists' => true,
            'hasOverrides' => false,
        ], 201, ['X-Invalidates' => 'system:environments']);
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
            'php_config' => $this->getPhpConfig(),
        ];

        return ApiResponse::create($data);
    }

    private function getPhpConfig(): array
    {
        $ini = function (string $key): string {
            return (string) ini_get($key);
        };

        return [
            'Upload & POST' => [
                'file_uploads' => $ini('file_uploads'),
                'upload_max_filesize' => $ini('upload_max_filesize'),
                'max_file_uploads' => $ini('max_file_uploads'),
                'post_max_size' => $ini('post_max_size'),
            ],
            'Memory & Execution' => [
                'memory_limit' => $ini('memory_limit'),
                'max_execution_time' => $ini('max_execution_time') . 's',
                'max_input_time' => $ini('max_input_time') . 's',
                'max_input_vars' => $ini('max_input_vars'),
            ],
            'Error Handling' => [
                'display_errors' => $ini('display_errors'),
                'error_reporting' => (string) error_reporting(),
                'log_errors' => $ini('log_errors'),
                'error_log' => $ini('error_log') ?: '(none)',
            ],
            'Paths & Environment' => [
                'open_basedir' => $ini('open_basedir') ?: '(none)',
                'sys_temp_dir' => sys_get_temp_dir(),
                'doc_root' => $_SERVER['DOCUMENT_ROOT'] ?? '(unknown)',
                'include_path' => $ini('include_path'),
            ],
            'Session' => [
                'session.save_handler' => $ini('session.save_handler'),
                'session.save_path' => $ini('session.save_path') ?: '(default)',
                'session.gc_maxlifetime' => $ini('session.gc_maxlifetime') . 's',
                'session.cookie_lifetime' => $ini('session.cookie_lifetime') . 's',
                'session.cookie_secure' => $ini('session.cookie_secure'),
                'session.cookie_httponly' => $ini('session.cookie_httponly'),
            ],
            'OPcache' => function_exists('opcache_get_status') ? [
                'opcache.enable' => $ini('opcache.enable'),
                'opcache.memory_consumption' => $ini('opcache.memory_consumption') . 'MB',
                'opcache.max_accelerated_files' => $ini('opcache.max_accelerated_files'),
                'opcache.validate_timestamps' => $ini('opcache.validate_timestamps'),
                'opcache.revalidate_freq' => $ini('opcache.revalidate_freq') . 's',
            ] : ['opcache.enable' => '0'],
            'Security' => [
                'allow_url_fopen' => $ini('allow_url_fopen'),
                'allow_url_include' => $ini('allow_url_include'),
                'disable_functions' => $ini('disable_functions') ?: '(none)',
                'expose_php' => $ini('expose_php'),
            ],
            'Date & Locale' => [
                'date.timezone' => $ini('date.timezone') ?: date_default_timezone_get(),
                'default_charset' => $ini('default_charset'),
                'mbstring.internal_encoding' => $ini('mbstring.internal_encoding') ?: '(default)',
            ],
        ];
    }

    /**
     * GET /ping - Lightweight keep-alive endpoint.
     * Health/connectivity check. No auth required — session keep-alive
     * is handled by proactive token refresh on the client side.
     */
    public function ping(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create(['pong' => true]);
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
        $search = $query['search'] ?? null;

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

            if ($search !== null && $search !== '' && stripos($message, $search) === false) {
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

        // Ensure backup directory is initialized before listing
        $backups = $this->grav['backups'] ?? new Backups();
        if (method_exists($backups, 'init')) {
            $backups->init();
        }

        $list = Backups::getAvailableBackups(true);

        $items = [];
        foreach ($list as $backup) {
            // getAvailableBackups returns stdClass objects, not arrays
            $b = is_object($backup) ? $backup : (object) $backup;
            $items[] = [
                'filename' => $b->filename ?? basename($b->path ?? ''),
                'title' => $b->title ?? null,
                'date' => $b->date ?? null,
                'size' => $b->size ?? 0,
            ];
        }

        // Include purge config for storage usage display
        $purge = Backups::getPurgeConfig();

        return ApiResponse::create([
            'backups' => $items,
            'purge' => $purge,
            'profiles_count' => count(Backups::getBackupProfiles() ?? []),
        ]);
    }

    /**
     * DELETE /system/backups/{filename} - Delete a backup file.
     */
    public function deleteBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $b = $this->grav['backups'] ?? new Backups();
        if (method_exists($b, 'init')) { $b->init(); }

        $filename = $this->getRouteParam($request, 'filename');

        // Validate filename (no path traversal)
        if (!$filename || $filename !== basename($filename) || !str_ends_with($filename, '.zip')) {
            throw new ValidationException(['filename' => ['Invalid backup filename.']]);
        }

        $backupDir = $this->grav['locator']->findResource('backup://', true);
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            throw new NotFoundException("Backup '{$filename}' not found.");
        }

        unlink($filepath);

        return ApiResponse::noContent();
    }

    /**
     * GET /system/backups/{filename}/download - Download a backup file.
     */
    public function downloadBackup(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $b = $this->grav['backups'] ?? new Backups();
        if (method_exists($b, 'init')) { $b->init(); }

        $filename = $this->getRouteParam($request, 'filename');

        if (!$filename || $filename !== basename($filename) || !str_ends_with($filename, '.zip')) {
            throw new ValidationException(['filename' => ['Invalid backup filename.']]);
        }

        $backupDir = $this->grav['locator']->findResource('backup://', true);
        $filepath = $backupDir . '/' . $filename;

        if (!file_exists($filepath)) {
            throw new NotFoundException("Backup '{$filename}' not found.");
        }

        $stream = fopen($filepath, 'rb');

        return new \Grav\Framework\Psr7\Response(
            200,
            [
                'Content-Type' => 'application/zip',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => (string) filesize($filepath),
            ],
            $stream,
        );
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

        // Strip strings contributed only by disabled plugins. Grav core's
        // `flattenByLang()` reads every plugin's lang yaml regardless of enabled
        // state — fine for the legacy admin, broken for admin2: a disabled plugin
        // would still influence what admin2 renders. The service walks each
        // plugin's lang yaml to determine provenance and returns keys unique to
        // disabled plugins. Keys also shipped by enabled sources stay.
        if (is_array($translations)) {
            $disabledIndex = new DisabledPluginLangIndex($this->grav);
            foreach ($disabledIndex->disabledOnlyKeys($lang) as $key) {
                unset($translations[$key]);
            }
        }

        // Drop flat `<key>` entries when an `ICU.<key>` shadow exists. Admin2 ships
        // the canonical PLUGIN_ADMIN.* vocabulary under ICU; if a 3rd-party plugin
        // still using the Grav 1 flat convention is also installed, its values
        // would otherwise leak into the dictionary served to the client. Keeping
        // only the ICU side guarantees admin2 is the source of truth.
        if (is_array($translations)) {
            foreach (array_keys($translations) as $key) {
                if (is_string($key) && !str_starts_with($key, 'ICU.') && isset($translations['ICU.' . $key])) {
                    unset($translations[$key]);
                }
            }
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
            $name = $plugin->name;
            // Plugin::getBlueprint() asserts the plugin's metadata is in
            // the Plugins manager. On Grav 2.0-rc.2 a number of registered
            // plugin instances have no companion entry there (login, form,
            // error, several first-party + side-car plugins), and the
            // assert blows up for the whole /system/info request. Fall
            // back to a read-from-disk path so partial info still ships.
            $bpName = null;
            $bpVersion = null;
            if ($gpm->get($name) !== null) {
                try {
                    $blueprint = $plugin->getBlueprint();
                    $bpName = $blueprint->get('name');
                    $bpVersion = $blueprint->get('version');
                } catch (\Throwable $e) {
                    // Defensive: even past the null check, blueprint
                    // hydration can throw on malformed yaml. Treat as
                    // metadata-unavailable.
                }
            } else {
                // Direct file read — bypasses Plugin::loadBlueprint() entirely.
                $file = GRAV_ROOT . "/user/plugins/{$name}/blueprints.yaml";
                if (is_file($file)) {
                    try {
                        $raw = \Symfony\Component\Yaml\Yaml::parseFile($file);
                        if (is_array($raw)) {
                            $bpName = $raw['name'] ?? null;
                            $bpVersion = $raw['version'] ?? null;
                        }
                    } catch (\Throwable $e) {
                        // ignore — leave metadata blank
                    }
                }
            }

            $plugins[] = [
                'name' => $bpName ?? $name,
                'version' => $bpVersion ?? '0.0.0',
                'enabled' => $this->config->get("plugins.{$name}.enabled", false),
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
