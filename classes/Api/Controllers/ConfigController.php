<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

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

        // Wrap in a Data object for admin event compatibility
        $obj = new Data($merged);

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

        return $this->respondWithEtag($data);
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
        $configDir = $this->grav['locator']->findResource('user://config', true, true);
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
