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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class GpmController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.gpm.read';
    private const PERMISSION_WRITE = 'api.gpm.write';

    private readonly PackageSerializer $serializer;

    public function __construct(\Grav\Common\Grav $grav, \Grav\Common\Config\Config $config)
    {
        parent::__construct($grav, $config);
        $this->serializer = new PackageSerializer();
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
}
