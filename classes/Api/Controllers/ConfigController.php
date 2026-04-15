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
        $data = $this->config->get($configKey);

        if ($data === null) {
            throw new NotFoundException("Configuration scope '{$scope}' not found.");
        }

        // Normalize to array for consistent response
        $data = is_array($data) ? $data : ['value' => $data];

        // Redact sensitive fields
        $data = $this->redactSensitiveFields($data);

        return $this->respondWithEtag($data);
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

        // ETag validation for conflict detection
        // Redact before hashing so it matches the ETag the client received from show()
        $existingArray = is_array($existing) ? $existing : ['value' => $existing];
        $currentHash = $this->generateEtag($this->redactSensitiveFields($existingArray));
        $this->validateEtag($request, $currentHash);

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

        $data = is_array($merged) ? $merged : ['value' => $merged];

        // Emit invalidations — plugin config changes also invalidate the plugins list.
        $tags = ['config:update:' . $scope];
        if (str_starts_with($scope, 'plugins/')) {
            $pluginName = substr($scope, 8);
            $tags[] = 'plugins:update:' . $pluginName;
            $tags[] = 'plugins:list';
        }

        return $this->respondWithEtag($data, 200, $tags);
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
     * Uses the `config://` stream so the path honors Grav's environment
     * override (e.g. user/env/localhost/config) the same way admin-classic does.
     */
    private function resolveConfigFile(string $scope): ?string
    {
        $configDir = $this->grav['locator']->findResource('config://', true, true);
        if (!$configDir) {
            return null;
        }

        return match (true) {
            $scope === 'system' => $configDir . '/system.yaml',
            $scope === 'site' => $configDir . '/site.yaml',
            $scope === 'media' => $configDir . '/media.yaml',
            $scope === 'security' => $configDir . '/security.yaml',
            $scope === 'scheduler' => $configDir . '/scheduler.yaml',
            $scope === 'backups' => $configDir . '/backups.yaml',
            str_starts_with($scope, 'plugins/') => $configDir . '/plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => $configDir . '/themes/' . substr($scope, 7) . '.yaml',
            default => null,
        };
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
        $configDir = $this->grav['locator']->findResource('config://', true, true);
        $yaml = Yaml::dump($data);

        $filePath = match (true) {
            $scope === 'system' => $configDir . '/system.yaml',
            $scope === 'site' => $configDir . '/site.yaml',
            $scope === 'media' => $configDir . '/media.yaml',
            $scope === 'security' => $configDir . '/security.yaml',
            $scope === 'scheduler' => $configDir . '/scheduler.yaml',
            $scope === 'backups' => $configDir . '/backups.yaml',
            str_starts_with($scope, 'plugins/') => $configDir . '/plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => $configDir . '/themes/' . substr($scope, 7) . '.yaml',
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($filePath, $yaml);
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
