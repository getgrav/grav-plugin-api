<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Services\ConfigDiffer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Read/write the Flex accounts configuration at user/config/flex/accounts.yaml.
 *
 * Classic admin exposes this as the "Configuration" tab under Users — it
 * carries the Flex compatibility toggles and any caching options the
 * Flex-Objects plugin contributes to user-accounts.
 *
 * Gated on admin.super, matching the security@ on the underlying blueprint.
 */
class AccountsConfigController extends AbstractApiController
{
    private const CONFIG_KEY = 'flex.accounts';
    private const CONFIG_FILE = 'flex/accounts.yaml';

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperOrAdmin($request);

        $data = $this->readConfig();
        return $this->respondWithEtag($data);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuperOrAdmin($request);

        $current = $this->readConfig();
        $this->validateEtag($request, $this->generateEtag($current));

        $body = $this->getRequestBody($request);
        if (empty($body)) {
            throw new ValidationException('Request body must contain configuration values to update.');
        }

        $merged = $this->mergePatch($current, $body);
        $this->writeConfig($merged);

        $this->fireEvent('onApiConfigUpdated', ['scope' => 'flex/accounts', 'data' => $merged]);

        return $this->respondWithEtag(
            $this->readConfig(),
            200,
            ['config:update:flex/accounts'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readConfig(): array
    {
        $data = $this->config->get(self::CONFIG_KEY);
        return is_array($data) ? $data : [];
    }

    /**
     * Persist to user/config/flex/accounts.yaml. We always write to base
     * user/config — env overlays for this file would be unusual and the
     * classic admin doesn't support them either.
     *
     * @param array<string, mixed> $data
     */
    private function writeConfig(array $data): void
    {
        $grav = Grav::instance();
        $locator = $grav['locator'];
        $userConfig = $locator->findResource('user://config', true);
        if (!$userConfig) {
            throw new \RuntimeException('Base user/config directory not found.');
        }

        $filePath = $userConfig . '/' . self::CONFIG_FILE;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Skip persisting any values injected via GRAV_CONFIG__* env vars (.env);
        // they win at runtime and must not be written into the config on disk.
        $forFile = (new ConfigDiffer($grav))->stripEnvironmentOverrides($data, 'flex/accounts');
        file_put_contents($filePath, Yaml::dump($forFile, 99, 2));

        $this->config->set(self::CONFIG_KEY, $data);
        $grav['cache']->clearCache('standard');
    }

    private function requireSuperOrAdmin(ServerRequestInterface $request): void
    {
        $user = $this->getUser($request);
        if ($this->isSuperAdmin($user)) {
            return;
        }
        $this->requirePermission($request, 'admin.super');
    }
}
