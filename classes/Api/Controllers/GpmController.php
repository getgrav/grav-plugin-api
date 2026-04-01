<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Cache;
use Grav\Common\GPM\GPM;
use Grav\Common\GPM\Installer;
use Grav\Common\GPM\Licenses;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\PackageSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\Event\Event;

class GpmController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.gpm.read';
    private const PERMISSION_WRITE = 'api.gpm.write';

    private readonly PackageSerializer $serializer;
    private readonly ThumbnailService $thumbSmall;
    private readonly ThumbnailService $thumbLarge;

    public function __construct(\Grav\Common\Grav $grav, \Grav\Common\Config\Config $config)
    {
        parent::__construct($grav, $config);
        $this->serializer = new PackageSerializer();
        $cacheDir = $grav['locator']->findResource('cache://', true, true) . '/api/thumbnails';
        $this->thumbSmall = new ThumbnailService($cacheDir, 500);
        $this->thumbLarge = new ThumbnailService($cacheDir, 2000);
    }

    /**
     * GET /gpm/plugins - List all installed plugins with update status.
     */
    public function plugins(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $gpm = $this->getGpm();
        $installed = $gpm->getInstalledPlugins();
        $updatable = $gpm->getUpdatablePlugins();

        $plugins = [];
        foreach ($installed as $slug => $plugin) {
            $data = $this->serializer->serialize($plugin, ['type' => 'plugin', 'installed' => true]);
            if (isset($updatable[$slug])) {
                $data['available_version'] = $updatable[$slug]->available;
                $data['updatable'] = true;
            } else {
                $data['updatable'] = false;
            }
            $plugins[] = $data;
        }

        return ApiResponse::create($plugins);
    }

    /**
     * GET /gpm/plugins/{slug} - Get details for a specific installed plugin.
     */
    public function plugin(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $gpm = $this->getGpm();

        $plugin = $gpm->getInstalledPlugin($slug);
        if (!$plugin) {
            throw new NotFoundException("Plugin '{$slug}' is not installed.");
        }

        $data = $this->serializer->serialize($plugin, ['type' => 'plugin', 'installed' => true]);

        if ($gpm->isPluginUpdatable($slug)) {
            $updatable = $gpm->getUpdatablePlugins();
            $data['available_version'] = $updatable[$slug]->available ?? null;
            $data['updatable'] = true;
        } else {
            $data['updatable'] = false;
        }

        // Discover custom admin-next field components
        $customFields = $this->discoverCustomFields($slug, 'plugins');
        if ($customFields) {
            $data['custom_fields'] = $customFields;
        }

        return $this->respondWithEtag($data);
    }

    /**
     * GET /gpm/themes - List all installed themes with update status.
     */
    public function themes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $gpm = $this->getGpm();
        $installed = $gpm->getInstalledThemes();
        $updatable = $gpm->getUpdatableThemes();

        $themes = [];
        foreach ($installed as $slug => $theme) {
            $data = $this->serializer->serialize($theme, ['type' => 'theme', 'installed' => true]);
            if (isset($updatable[$slug])) {
                $data['available_version'] = $updatable[$slug]->available;
                $data['updatable'] = true;
            } else {
                $data['updatable'] = false;
            }
            $images = $this->getThemeImages($slug);
            $data['thumbnail'] = $images['thumbnail'];
            $data['screenshot'] = $images['screenshot'];
            $themes[] = $data;
        }

        return ApiResponse::create($themes);
    }

    /**
     * GET /gpm/themes/{slug} - Get details for a specific installed theme.
     */
    public function theme(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $gpm = $this->getGpm();

        $theme = $gpm->getInstalledTheme($slug);
        if (!$theme) {
            throw new NotFoundException("Theme '{$slug}' is not installed.");
        }

        $data = $this->serializer->serialize($theme, ['type' => 'theme', 'installed' => true]);

        if ($gpm->isThemeUpdatable($slug)) {
            $updatable = $gpm->getUpdatableThemes();
            $data['available_version'] = $updatable[$slug]->available ?? null;
            $data['updatable'] = true;
        } else {
            $data['updatable'] = false;
        }

        $images = $this->getThemeImages($slug);
        $data['thumbnail'] = $images['thumbnail'];
        $data['screenshot'] = $images['screenshot'];

        return $this->respondWithEtag($data);
    }

    /**
     * GET /gpm/updates - Check for available updates (plugins, themes, grav).
     */
    public function updates(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $query = $request->getQueryParams();
        $flush = filter_var($query['flush'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $gpm = $this->getGpm($flush);
        $updatable = $gpm->getUpdatable();
        $gravInfo = $gpm->getGrav();

        $data = [
            'grav' => [
                'current' => GRAV_VERSION,
                'available' => $gravInfo ? $gravInfo->getVersion() : null,
                'updatable' => $gravInfo ? $gravInfo->isUpdatable() : false,
                'date' => $gravInfo ? $gravInfo->getDate() : null,
                'is_symlink' => $gravInfo ? $gravInfo->isSymlink() : false,
            ],
            'plugins' => $this->serializer->serializeCollection(
                $updatable['plugins'] ?? [],
                ['type' => 'plugin', 'installed' => true]
            ),
            'themes' => $this->serializer->serializeCollection(
                $updatable['themes'] ?? [],
                ['type' => 'theme', 'installed' => true]
            ),
            'total' => $updatable['total'] ?? 0,
            'installed' => $gpm->countInstalled(),
        ];

        return ApiResponse::create($data);
    }

    /**
     * POST /gpm/install - Install a plugin or theme by slug.
     */
    public function install(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['package']);

        $package = $body['package'];
        $type = $body['type'] ?? 'plugin';

        if (!in_array($type, ['plugin', 'theme'], true)) {
            throw new ValidationException("Invalid package type '{$type}'. Must be 'plugin' or 'theme'.");
        }

        // Check if already installed
        $gpm = $this->getGpm();
        $alreadyInstalled = $type === 'plugin'
            ? $gpm->isPluginInstalled($package)
            : $gpm->isThemeInstalled($package);

        if ($alreadyInstalled) {
            throw new ValidationException(ucfirst($type) . " '{$package}' is already installed. Use the update endpoint to update it.");
        }

        // Handle premium license — store if provided, check if needed
        $license = $body['license'] ?? null;
        if ($license) {
            if (!Licenses::validate($license)) {
                throw new ValidationException(
                    "Invalid license format. Expected: XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX (uppercase hex)."
                );
            }
            Licenses::set($package, $license);
        }

        // Check if premium package has a license available
        $repoPackage = $gpm->findPackage($package, true);
        if ($repoPackage && !empty($repoPackage->premium) && !Licenses::get($package)) {
            throw new ValidationException(
                "'{$package}' is a premium package and requires a license. Pass a 'license' field in the request body, or upload a license via the license-manager plugin/API."
            );
        }

        $this->fireEvent('onApiBeforePackageInstall', [
            'package' => $package,
            'type' => $type,
        ]);

        try {
            $result = \Grav\Plugin\Admin\Gpm::install($package, [
                'theme' => $type === 'theme',
            ]);
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Installation Failed', $e->getMessage());
        }

        if ($result !== true) {
            $message = is_string($result) ? $result : "Failed to install {$type} '{$package}'.";
            throw new ApiException(500, 'Installation Failed', $message);
        }

        $this->fireEvent('onApiPackageInstalled', [
            'package' => $package,
            'type' => $type,
        ]);

        return ApiResponse::create([
            'message' => ucfirst($type) . " '{$package}' installed successfully.",
            'package' => $package,
            'type' => $type,
        ], 201);
    }

    /**
     * POST /gpm/remove - Remove a plugin or theme.
     */
    public function remove(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['package']);

        $package = $body['package'];

        // Check if the package is installed
        $gpm = $this->getGpm();
        $isPlugin = $gpm->isPluginInstalled($package);
        $isTheme = $gpm->isThemeInstalled($package);

        if (!$isPlugin && !$isTheme) {
            throw new NotFoundException("Package '{$package}' is not installed.");
        }

        $type = $isPlugin ? 'plugin' : 'theme';

        $this->fireEvent('onApiBeforePackageRemove', [
            'package' => $package,
            'type' => $type,
        ]);

        try {
            $result = \Grav\Plugin\Admin\Gpm::uninstall($package, []);
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Removal Failed', $e->getMessage());
        }

        if ($result !== true) {
            $message = is_string($result) ? $result : "Failed to remove {$type} '{$package}'.";
            throw new ApiException(500, 'Removal Failed', $message);
        }

        $this->fireEvent('onApiPackageRemoved', [
            'package' => $package,
            'type' => $type,
        ]);

        return ApiResponse::noContent();
    }

    /**
     * POST /gpm/update - Update a specific plugin or theme.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['package']);

        $package = $body['package'];

        $gpm = $this->getGpm();
        if (!$gpm->isUpdatable($package)) {
            throw new ValidationException("Package '{$package}' is not updatable or not installed.");
        }

        try {
            $result = \Grav\Plugin\Admin\Gpm::update($package, []);
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Update Failed', $e->getMessage());
        }

        if ($result !== true) {
            $message = is_string($result) ? $result : "Failed to update '{$package}'.";
            throw new ApiException(500, 'Update Failed', $message);
        }

        return ApiResponse::create([
            'message' => "Package '{$package}' updated successfully.",
            'package' => $package,
        ]);
    }

    /**
     * POST /gpm/update-all - Update all updatable packages.
     */
    public function updateAll(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $gpm = $this->getGpm(true);
        $updatable = $gpm->getUpdatable();

        $results = ['updated' => [], 'failed' => []];

        // Update plugins
        foreach ($updatable['plugins'] ?? [] as $slug => $plugin) {
            try {
                $result = \Grav\Plugin\Admin\Gpm::update($slug, []);
                if ($result === true) {
                    $results['updated'][] = $slug;
                } else {
                    $results['failed'][] = ['package' => $slug, 'error' => is_string($result) ? $result : 'Unknown error'];
                }
            } catch (\Throwable $e) {
                $results['failed'][] = ['package' => $slug, 'error' => $e->getMessage()];
            }
        }

        // Update themes
        foreach ($updatable['themes'] ?? [] as $slug => $theme) {
            try {
                $result = \Grav\Plugin\Admin\Gpm::update($slug, ['theme' => true]);
                if ($result === true) {
                    $results['updated'][] = $slug;
                } else {
                    $results['failed'][] = ['package' => $slug, 'error' => is_string($result) ? $result : 'Unknown error'];
                }
            } catch (\Throwable $e) {
                $results['failed'][] = ['package' => $slug, 'error' => $e->getMessage()];
            }
        }

        return ApiResponse::create($results);
    }

    /**
     * POST /gpm/upgrade - Self-upgrade Grav core.
     */
    public function upgrade(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $gpm = $this->getGpm(true);
        $gravInfo = $gpm->getGrav();

        if (!$gravInfo || !$gravInfo->isUpdatable()) {
            throw new ValidationException('Grav is already at the latest version.');
        }

        if ($gravInfo->isSymlink()) {
            throw new ValidationException('Cannot upgrade Grav when installed via symlink.');
        }

        $this->fireEvent('onApiBeforeGravUpgrade', [
            'current_version' => GRAV_VERSION,
            'available_version' => $gravInfo->getVersion(),
        ]);

        try {
            $result = \Grav\Plugin\Admin\Gpm::selfupgrade();
        } catch (\Throwable $e) {
            throw new ApiException(500, 'Upgrade Failed', $e->getMessage());
        }

        if (!$result) {
            throw new ApiException(500, 'Upgrade Failed', 'Failed to upgrade Grav core.');
        }

        $this->fireEvent('onApiGravUpgraded', [
            'previous_version' => GRAV_VERSION,
            'new_version' => $gravInfo->getVersion(),
        ]);

        return ApiResponse::create([
            'message' => 'Grav upgraded successfully.',
            'previous_version' => GRAV_VERSION,
            'new_version' => $gravInfo->getVersion(),
        ]);
    }

    /**
     * POST /gpm/direct-install - Install from URL or uploaded zip.
     */
    public function directInstall(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

        $this->requireAdminGpm();

        $body = $this->getRequestBody($request);

        // Support URL-based install
        if (isset($body['url'])) {
            $packageFile = $body['url'];
        } else {
            // Check for uploaded file
            $uploadedFiles = $request->getUploadedFiles();
            $file = $uploadedFiles['file'] ?? null;

            if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
                throw new ValidationException('Either a "url" field or an uploaded "file" is required.');
            }

            // Move uploaded file to tmp
            $tmpDir = $this->grav['locator']->findResource('tmp://', true, true);
            $tmpFile = $tmpDir . '/api-upload-' . uniqid() . '.zip';
            $file->moveTo($tmpFile);
            $packageFile = $tmpFile;
        }

        try {
            $result = \Grav\Plugin\Admin\Gpm::directInstall($packageFile);
        } catch (\Throwable $e) {
            // Clean up tmp file on error
            if (isset($tmpFile) && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            throw new ApiException(500, 'Installation Failed', $e->getMessage());
        }

        // Clean up tmp file if we created one
        if (isset($tmpFile) && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        if ($result !== true) {
            $message = is_string($result) ? $result : 'Direct install failed.';
            throw new ApiException(500, 'Installation Failed', $message);
        }

        return ApiResponse::create([
            'message' => 'Package installed successfully via direct install.',
        ], 201);
    }

    /**
     * GET /gpm/repository/plugins - List available plugins from GPM repository.
     */
    public function repositoryPlugins(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $pagination = $this->getPagination($request);
        // Allow fetching all repository packages (the install modal needs the full list)
        $query = $request->getQueryParams();
        if (isset($query['per_page']) && (int) $query['per_page'] > $pagination['per_page']) {
            $requested = min(2000, (int) $query['per_page']);
            $pagination['per_page'] = $requested;
            $pagination['limit'] = $requested;
        }
        $gpm = $this->getGpm();

        $repoPlugins = $gpm->getRepositoryPlugins();
        if ($repoPlugins === null) {
            throw new ApiException(502, 'Bad Gateway', 'Unable to reach GPM repository.');
        }

        $query = $request->getQueryParams();
        $search = $query['q'] ?? null;

        $allPlugins = [];
        foreach ($repoPlugins as $slug => $plugin) {
            if ($search && !$this->matchesSearch($plugin, $slug, $search)) {
                continue;
            }
            $data = $this->serializer->serialize($plugin, ['type' => 'plugin']);
            $data['installed'] = $gpm->isPluginInstalled($slug);
            $allPlugins[] = $data;
        }

        $total = count($allPlugins);
        $slice = array_slice($allPlugins, $pagination['offset'], $pagination['limit']);
        $baseUrl = $this->getApiBaseUrl() . '/gpm/repository/plugins';

        return ApiResponse::paginated(
            data: $slice,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $baseUrl,
        );
    }

    /**
     * GET /gpm/repository/themes - List available themes from GPM repository.
     */
    public function repositoryThemes(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        if (isset($query['per_page']) && (int) $query['per_page'] > $pagination['per_page']) {
            $requested = min(2000, (int) $query['per_page']);
            $pagination['per_page'] = $requested;
            $pagination['limit'] = $requested;
        }
        $gpm = $this->getGpm();

        $repoThemes = $gpm->getRepositoryThemes();
        if ($repoThemes === null) {
            throw new ApiException(502, 'Bad Gateway', 'Unable to reach GPM repository.');
        }

        $query = $request->getQueryParams();
        $search = $query['q'] ?? null;

        $allThemes = [];
        foreach ($repoThemes as $slug => $theme) {
            if ($search && !$this->matchesSearch($theme, $slug, $search)) {
                continue;
            }
            $data = $this->serializer->serialize($theme, ['type' => 'theme']);
            $data['installed'] = $gpm->isThemeInstalled($slug);
            $allThemes[] = $data;
        }

        $total = count($allThemes);
        $slice = array_slice($allThemes, $pagination['offset'], $pagination['limit']);
        $baseUrl = $this->getApiBaseUrl() . '/gpm/repository/themes';

        return ApiResponse::paginated(
            data: $slice,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $baseUrl,
        );
    }

    /**
     * GET /gpm/repository/{slug} - Get repository details for a package.
     */
    public function repositoryPackage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $gpm = $this->getGpm();

        $package = $gpm->findPackage($slug, true);
        if (!$package) {
            throw new NotFoundException("Package '{$slug}' not found in GPM repository.");
        }

        $isPlugin = $gpm->getRepositoryPlugin($slug) !== null;
        $type = $isPlugin ? 'plugin' : 'theme';

        $data = $this->serializer->serialize($package, ['type' => $type]);
        $data['installed'] = $isPlugin
            ? $gpm->isPluginInstalled($slug)
            : $gpm->isThemeInstalled($slug);

        return ApiResponse::create($data);
    }

    /**
     * GET /gpm/search - Search across all repository packages (plugins + themes).
     */
    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $query = $request->getQueryParams();
        $search = $query['q'] ?? null;

        if (!$search || trim($search) === '') {
            throw new ValidationException("The 'q' query parameter is required for search.");
        }

        $pagination = $this->getPagination($request);
        $gpm = $this->getGpm();

        $results = [];

        $repoPlugins = $gpm->getRepositoryPlugins();
        if ($repoPlugins) {
            foreach ($repoPlugins as $slug => $plugin) {
                if ($this->matchesSearch($plugin, $slug, $search)) {
                    $data = $this->serializer->serialize($plugin, ['type' => 'plugin']);
                    $data['installed'] = $gpm->isPluginInstalled($slug);
                    $results[] = $data;
                }
            }
        }

        $repoThemes = $gpm->getRepositoryThemes();
        if ($repoThemes) {
            foreach ($repoThemes as $slug => $theme) {
                if ($this->matchesSearch($theme, $slug, $search)) {
                    $data = $this->serializer->serialize($theme, ['type' => 'theme']);
                    $data['installed'] = $gpm->isThemeInstalled($slug);
                    $results[] = $data;
                }
            }
        }

        $total = count($results);
        $slice = array_slice($results, $pagination['offset'], $pagination['limit']);
        $baseUrl = $this->getApiBaseUrl() . '/gpm/search';

        return ApiResponse::paginated(
            data: $slice,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $baseUrl,
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Get a GPM instance.
     */
    private function getGpm(bool $refresh = false): GPM
    {
        return new GPM($refresh);
    }

    /**
     * Resolve thumbnail and screenshot URLs for an installed theme.
     * Returns ['thumbnail' => url|null, 'screenshot' => url|null].
     */
    private function getThemeImages(string $slug): array
    {
        $result = ['thumbnail' => null, 'screenshot' => null];

        try {
            $path = $this->resolvePackagePath($slug, 'themes');
        } catch (NotFoundException) {
            return $result;
        }

        // Thumbnail (small, capped at 500px for list views)
        foreach (['thumbnail.jpg', 'thumbnail.png'] as $file) {
            $source = $path . '/' . $file;
            if (file_exists($source)) {
                $filename = $this->thumbSmall->getThumbnailFilename($source);
                if ($filename) {
                    $this->thumbSmall->getThumbnail($source);
                    $result['thumbnail'] = $this->getApiBaseUrl() . '/thumbnails/' . $filename;
                    break;
                }
            }
        }

        // Screenshot (large, capped at 2000px for detail/preview)
        foreach (['screenshot.jpg', 'screenshot.png'] as $file) {
            $source = $path . '/' . $file;
            if (file_exists($source)) {
                $filename = $this->thumbLarge->getThumbnailFilename($source);
                if ($filename) {
                    $this->thumbLarge->getThumbnail($source);
                    $result['screenshot'] = $this->getApiBaseUrl() . '/thumbnails/' . $filename;
                    break;
                }
            }
        }

        // Fall back: if no thumbnail but screenshot exists, use screenshot for both
        if (!$result['thumbnail'] && $result['screenshot']) {
            $result['thumbnail'] = $result['screenshot'];
        }
        // Vice versa
        if (!$result['screenshot'] && $result['thumbnail']) {
            $result['screenshot'] = $result['thumbnail'];
        }

        return $result;
    }

    /**
     * Ensure the admin Gpm class is available (requires admin plugin).
     */
    private function requireAdminGpm(): void
    {
        if (!class_exists(\Grav\Plugin\Admin\Gpm::class)) {
            throw new ApiException(
                500,
                'Admin Plugin Required',
                'GPM write operations require the Grav admin plugin to be installed.'
            );
        }
    }

    /**
     * Check if a package matches a search query (slug, name, description, author, keywords).
     */
    private function matchesSearch(object $package, string $slug, string $search): bool
    {
        $search = strtolower($search);

        // Match against slug
        if (str_contains(strtolower($slug), $search)) {
            return true;
        }

        // Match against name
        $name = $package->name ?? '';
        if ($name && str_contains(strtolower($name), $search)) {
            return true;
        }

        // Match against description
        $description = $package->description ?? '';
        if ($description && str_contains(strtolower($description), $search)) {
            return true;
        }

        // Match against author name
        $author = $package->author ?? null;
        if ($author) {
            $authorName = is_object($author) ? ($author->name ?? '') : ($author['name'] ?? '');
            if ($authorName && str_contains(strtolower($authorName), $search)) {
                return true;
            }
        }

        // Match against keywords
        $keywords = $package->keywords ?? [];
        if (is_array($keywords)) {
            foreach ($keywords as $keyword) {
                if (str_contains(strtolower($keyword), $search)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * GET /gpm/plugins/{slug}/readme - Get plugin README.md content.
     * GET /gpm/themes/{slug}/readme
     */
    public function readme(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $type = str_contains($request->getUri()->getPath(), '/themes/') ? 'themes' : 'plugins';

        $path = $this->resolvePackagePath($slug, $type);
        $file = $path . '/README.md';

        if (!file_exists($file)) {
            throw new NotFoundException("No README found for '{$slug}'.");
        }

        return ApiResponse::create([
            'content' => file_get_contents($file),
        ]);
    }

    /**
     * GET /gpm/plugins/{slug}/changelog - Get plugin CHANGELOG.md content.
     * GET /gpm/themes/{slug}/changelog
     */
    public function changelog(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $type = str_contains($request->getUri()->getPath(), '/themes/') ? 'themes' : 'plugins';

        $path = $this->resolvePackagePath($slug, $type);
        $file = $path . '/CHANGELOG.md';

        if (!file_exists($file)) {
            throw new NotFoundException("No changelog found for '{$slug}'.");
        }

        return ApiResponse::create([
            'content' => file_get_contents($file),
        ]);
    }

    /**
     * Resolve the filesystem path for an installed package.
     */
    private function resolvePackagePath(string $slug, string $type): string
    {
        $base = $type === 'themes' ? 'themes' : 'plugins';
        $path = $this->grav['locator']->findResource("user://{$base}/{$slug}", true);

        if (!$path || !is_dir($path)) {
            throw new NotFoundException("Package '{$slug}' not found.");
        }

        return $path;
    }

    /**
     * Discover custom admin-next field web components shipped by a package.
     *
     * Convention: plugins place field components at admin-next/fields/{type}.js
     * Each JS file should define a Custom Element that admin-next will load
     * on demand when encountering an unknown field type.
     *
     * @return array<string, string>|null Map of field type → relative script path, or null if none
     */
    private function discoverCustomFields(string $slug, string $type): ?array
    {
        try {
            $path = $this->resolvePackagePath($slug, $type);
        } catch (NotFoundException) {
            return null;
        }

        $fieldsDir = $path . '/admin-next/fields';
        if (!is_dir($fieldsDir)) {
            return null;
        }

        $fields = [];
        foreach (new \DirectoryIterator($fieldsDir) as $file) {
            if ($file->isDot() || !$file->isFile()) {
                continue;
            }
            if ($file->getExtension() === 'js') {
                $fieldType = $file->getBasename('.js');
                $fields[$fieldType] = $fieldType;
            }
        }

        return $fields ?: null;
    }

    /**
     * GET /gpm/{plugins|themes}/{slug}/field/{type} - Serve a custom field web component JS.
     *
     * Returns the JavaScript file for a custom admin-next field component.
     * The response is cached aggressively (1 year) since the content only
     * changes when the plugin is updated.
     */
    public function customFieldScript(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $fieldType = $this->getRouteParam($request, 'type');
        $pkgType = str_contains($request->getUri()->getPath(), '/themes/') ? 'themes' : 'plugins';

        $path = $this->resolvePackagePath($slug, $pkgType);
        $file = $path . '/admin-next/fields/' . basename($fieldType) . '.js';

        if (!file_exists($file)) {
            throw new NotFoundException("Custom field '{$fieldType}' not found for '{$slug}'.");
        }

        $content = file_get_contents($file);

        return new \Grav\Framework\Psr7\Response(
            200,
            [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            $content,
        );
    }

    /**
     * GET /gpm/plugins/{slug}/page — Get plugin page definition.
     *
     * Resolution order:
     * 1. Fire onApiPluginPageInfo event (plugin provides definition)
     * 2. Filesystem: admin-next/pages/{slug}.yaml definition file
     * 3. Filesystem: admin-next/pages/{slug}.js → infer component mode
     * 4. 404
     */
    public function pluginPage(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');

        // 1. Try event-based definition
        $event = new Event([
            'plugin' => $slug,
            'definition' => null,
            'user' => $this->getUser($request),
        ]);
        $this->grav->fireEvent('onApiPluginPageInfo', $event);

        if ($event['definition']) {
            $definition = $event['definition'];
            // Check if a page web component exists
            $definition['has_custom_component'] = $this->hasPluginPageScript($slug);
            return ApiResponse::create($definition);
        }

        // 2. Try filesystem discovery
        $definition = $this->discoverPluginPage($slug);
        if ($definition) {
            return ApiResponse::create($definition);
        }

        throw new NotFoundException("No admin page found for plugin '{$slug}'.");
    }

    /**
     * GET /gpm/plugins/{slug}/page-script — Serve a plugin page web component JS.
     */
    public function customPageScript(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        $slug = $this->getRouteParam($request, 'slug');
        $path = $this->resolvePackagePath($slug, 'plugins');
        $file = $path . '/admin-next/pages/' . basename($slug) . '.js';

        if (!file_exists($file)) {
            throw new NotFoundException("Page component not found for plugin '{$slug}'.");
        }

        $content = file_get_contents($file);

        return new \Grav\Framework\Psr7\Response(
            200,
            [
                'Content-Type' => 'application/javascript; charset=utf-8',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            $content,
        );
    }

    /**
     * Discover a plugin page definition from filesystem conventions.
     *
     * Checks for admin-next/pages/{slug}.yaml and admin-next/pages/{slug}.js
     */
    private function discoverPluginPage(string $slug): ?array
    {
        try {
            $path = $this->resolvePackagePath($slug, 'plugins');
        } catch (NotFoundException) {
            return null;
        }

        $pagesDir = $path . '/admin-next/pages';
        $yamlFile = $pagesDir . '/' . $slug . '.yaml';
        $jsFile = $pagesDir . '/' . $slug . '.js';

        // Try YAML definition
        if (file_exists($yamlFile)) {
            $data = \Grav\Common\Yaml::parse(file_get_contents($yamlFile));
            if (is_array($data)) {
                $data['has_custom_component'] = file_exists($jsFile);
                return $data;
            }
        }

        // Try JS component only (infer component mode)
        if (file_exists($jsFile)) {
            return [
                'id' => $slug,
                'plugin' => $slug,
                'title' => ucwords(str_replace('-', ' ', $slug)),
                'page_type' => 'component',
                'has_custom_component' => true,
            ];
        }

        return null;
    }

    /**
     * Check if a plugin ships a page-level web component.
     */
    private function hasPluginPageScript(string $slug): bool
    {
        try {
            $path = $this->resolvePackagePath($slug, 'plugins');
            return file_exists($path . '/admin-next/pages/' . basename($slug) . '.js');
        } catch (NotFoundException) {
            return false;
        }
    }
}
