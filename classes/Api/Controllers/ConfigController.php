<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ConfigController extends AbstractApiController
{
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
        $existingArray = is_array($existing) ? $existing : ['value' => $existing];
        $currentHash = $this->generateEtag($existingArray);
        $this->validateEtag($request, $currentHash);

        $body = $this->getRequestBody($request);

        if (empty($body)) {
            throw new ValidationException('Request body must contain configuration values to update.');
        }

        // Deep merge provided values with existing config
        $merged = is_array($existing)
            ? array_replace_recursive($existing, $body)
            : $body;

        // Update in-memory config
        $this->config->set($configKey, $merged);

        // Persist to the appropriate YAML file
        $this->writeConfigFile($scope, $merged);

        // Clear config cache
        $this->grav['cache']->clearCache('standard');

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
}
