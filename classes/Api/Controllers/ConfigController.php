<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Data\Blueprints;
use Grav\Common\Data\Data;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ConfigController extends AbstractApiController
{
    /**
     * GET /config - List available configuration sections.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        /** @var \RocketTheme\Toolbox\ResourceLocator\UniformResourceIterator $iterator */
        $iterator = $this->grav['locator']->getIterator('blueprints://config');

        $configurations = [];
        foreach ($iterator as $file) {
            if ($file->isDir() || !preg_match('/^[^.].*.yaml$/', $file->getFilename())) {
                continue;
            }
            $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            // Skip scheduler and backups (they belong to tools)
            if (in_array($name, ['scheduler', 'backups', 'streams'], true)) {
                continue;
            }
            $configurations[$name] = true;
        }

        // Sort and enforce canonical ordering: system, site first; info last
        ksort($configurations);
        $configurations = ['system' => true, 'site' => true] + $configurations + ['info' => true];

        return ApiResponse::create(array_keys($configurations));
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.read');

        $scope = $this->getRouteParam($request, 'scope');
        $configKey = $this->resolveConfigKey($scope);

        if ($this->config->get($configKey) === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        return $this->respondWithEtag($this->configEtagData($configKey));
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.config.write');

        $scope = $this->getRouteParam($request, 'scope');
        $configKey = $this->resolveConfigKey($scope);
        $existing = $this->config->get($configKey);

        if ($existing === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        // ETag validation — hash the same shape show() returned so If-Match matches.
        $this->validateEtag($request, $this->generateEtag($this->configEtagData($configKey)));

        $body = $this->getRequestBody($request);

        if (empty($body)) {
            throw new ValidationException('Request body must contain configuration values to update.');
        }

        // Deep merge provided values with existing config
        $merged = is_array($existing)
            ? array_replace_recursive($existing, $body)
            : $body;

        // Load the blueprint and apply field-type filtering (e.g., commalist → array)
        $blueprint = $this->loadBlueprint($scope);
        $obj = new Data($merged, $blueprint);
        $obj->filter(true, true);

        // Set the config file on the Data object so plugins (e.g., revisions-pro)
        // can read the file path for revision tracking.
        $configFile = $this->resolveConfigFile($scope);
        if ($configFile) {
            $obj->file(\RocketTheme\Toolbox\File\YamlFile::instance($configFile));
        }

        // Set the AdminProxy route so plugins that detect context from the admin
        // route (e.g., revisions-pro getDataType) work correctly in API context.
        $admin = $this->grav['admin'] ?? null;
        if ($admin && property_exists($admin, 'route')) {
            $admin->route = $this->scopeToAdminRoute($scope);
        }

        // Allow plugins to modify config before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$obj]);

        // Extract (potentially modified) data back from the Data object
        $merged = $obj->toArray();

        // Update in-memory config
        $this->config->set($configKey, $merged);

        // Persist to the appropriate YAML file
        $this->writeConfigFile($scope, $merged);

        // Clear config cache
        $this->grav['cache']->clearCache('standard');

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $obj]);
        $this->fireEvent('onApiConfigUpdated', ['scope' => $scope, 'data' => $merged]);

        // Emit invalidations — plugin config changes also invalidate the plugins list.
        $tags = ['config:update:' . $scope];
        if (str_starts_with($scope, 'plugins/')) {
            $pluginName = substr($scope, 8);
            $tags[] = 'plugins:update:' . $pluginName;
            $tags[] = 'plugins:list';
        }

        // Response hashes the same shape show() would return on the next GET,
        // so the client's stored ETag stays valid for subsequent saves.
        return $this->respondWithEtag($this->configEtagData($configKey), 200, $tags);
    }

    /**
     * Canonical representation used for both the response body and ETag hashing.
     * Reading via config->get() after save reflects any blueprint defaults or
     * type coercion Grav applies on the next request, keeping hashes stable.
     */
    private function configEtagData(string $configKey): array
    {
        $data = $this->config->get($configKey);
        $data = is_array($data) ? $data : ['value' => $data];
        return $this->redactSensitiveFields($data);
    }

    /**
     * Resolve the scope route parameter to a Grav config key.
     *
     * Supported scopes:
     *   - system          -> 'system'
     *   - site            -> 'site'
     *   - plugins/{name}  -> 'plugins.{name}'
     *   - themes/{name}   -> 'themes.{name}'
     */
    /**
     * Map a config scope to the admin route format that plugins expect.
     */
    private function scopeToAdminRoute(string $scope): string
    {
        return match (true) {
            str_starts_with($scope, 'plugins/') => '/' . $scope,
            str_starts_with($scope, 'themes/') => '/' . $scope,
            default => '/config/' . $scope,
        };
    }

    /**
     * Resolve the config file path for a given scope.
     *
     * Resolves to the explicit write target (base user/config by default).
     * We deliberately avoid the `config://` stream here because its first
     * resolved path can be an env folder Grav auto-inferred from the hostname,
     * which would create an unintended user/<host>/ folder on save.
     */
    private function resolveConfigFile(string $scope): ?string
    {
        try {
            return $this->resolveWriteDir() . '/' . $this->scopeFileName($scope);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Load the blueprint for the given config scope.
     *
     * Blueprints define field types (e.g., commalist) that determine how
     * values are coerced — without this, arrays may be saved as strings.
     */
    private function loadBlueprint(string $scope): ?\Grav\Common\Data\Blueprint
    {
        try {
            $blueprintKey = match (true) {
                in_array($scope, ['system', 'site', 'media', 'security', 'scheduler', 'backups']) => 'config/' . $scope,
                str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8),
                str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7),
                default => null,
            };

            if ($blueprintKey === null) {
                return null;
            }

            $blueprints = new Blueprints();
            return $blueprints->get($blueprintKey);
        } catch (\Exception $e) {
            // If blueprint can't be loaded, save without filtering
            return null;
        }
    }

    private function resolveConfigKey(?string $scope): string
    {
        if ($scope === null || $scope === '') {
            throw new ValidationException('Configuration scope is required.');
        }

        return match (true) {
            $scope === 'system' => 'system',
            $scope === 'site' => 'site',
            $scope === 'media' => 'media',
            $scope === 'security' => 'security',
            $scope === 'scheduler' => 'scheduler',
            $scope === 'backups' => 'backups',
            str_starts_with($scope, 'plugins/') => 'plugins.' . substr($scope, 8),
            str_starts_with($scope, 'themes/') => 'themes.' . substr($scope, 7),
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

    /**
     * Resolve the scope to a filesystem path and write the YAML config file.
     */
    private function writeConfigFile(string $scope, mixed $data): void
    {
        $filePath = $this->resolveWriteDir() . '/' . $this->scopeFileName($scope);

        // Only ever create plugin/theme sub-dirs inside an existing base write dir.
        // We never create env folders — those must be opted into explicitly.
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($filePath, Yaml::dump($data));
    }

    /**
     * Where config writes land.
     *
     * Always the base user/config/ in Phase 1. Phase 2 will layer an explicit
     * env override (via X-Config-Environment header) that must resolve to an
     * existing user/env/<env>/ — we never implicitly pick an env folder.
     */
    private function resolveWriteDir(): string
    {
        $userConfig = $this->grav['locator']->findResource('user://config', true);
        if (!$userConfig) {
            throw new \RuntimeException('Base user/config directory not found.');
        }
        return $userConfig;
    }

    /**
     * Filename for a scope, relative to a config directory.
     */
    private function scopeFileName(string $scope): string
    {
        return match (true) {
            in_array($scope, ['system', 'site', 'media', 'security', 'scheduler', 'backups'], true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

    /**
     * Redact sensitive fields from config output.
     */
    private function redactSensitiveFields(array $data): array
    {
        $sensitiveKeys = ['jwt_secret', 'secret', 'password', 'hashed_password', 'api_key', 'private_key'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (is_string($value) && in_array($key, $sensitiveKeys, true)) {
                $value = '********';
            }
        });

        return $data;
    }
}
