<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads and saves a config scope on the layer the request targets, the same way
 * the API's own config endpoint (ConfigController) does.
 *
 * Grav layers config as plugin/core defaults, then base `user/config`, then an
 * environment overlay (`user/env/<name>/config`, or legacy `user/<name>/config`)
 * for requests on that host. The merged `config://` stream resolves to the
 * overlay first, so a plugin that saves its settings there writes to whatever
 * environment the request booted under, not the one picked in Admin2's
 * environment selector (sent as `X-Config-Environment`, `default` = base). Use
 * this service instead:
 *
 *     $store  = new ConfigStore($this->grav);
 *     $target = $store->targetFor($request);            // null = base user/config
 *     $store->writeDir($target);                        // fails early if the env is missing
 *     $data   = $store->effective('plugins/my-plugin', $target);
 *     // ... merge the request body into $data ...
 *     $store->save('plugins/my-plugin', $data, $target);
 *
 * save() takes the FULL settings array and writes only what differs from the
 * layer beneath the target, so default values never get copied into the file.
 *
 * Added in API 1.0.50. The environment picker is an Admin2 feature, so a plugin
 * that also runs on Grav 1.7 with the classic admin, or next to an older API
 * plugin, needs no dependency bump: guard the class and keep the plugin's
 * previous save code as the fallback, so those sites behave exactly as before.
 *
 *     private function configStore(): ?ConfigStore
 *     {
 *         return class_exists(ConfigStore::class) ? new ConfigStore($this->grav) : null;
 *     }
 *
 *     $store = $this->configStore();
 *     if ($store === null) {
 *         return $this->legacySave($body);    // what the plugin always did
 *     }
 *     $target = $store->targetFor($request);
 *     // ... effective() / merge / save() as above ...
 *
 * Reference ConfigStore only from the plugin's API controller, which loads only
 * when the API plugin is present. Never from the plugin's main class or its
 * classic-admin code paths: those also run on sites with no API plugin at all.
 */
final class ConfigStore
{
    public function __construct(private Grav $grav)
    {
    }

    /**
     * Env name the request targets, or null for base.
     *
     *   header absent                  → the environment Grav booted under, when
     *                                    it has a config dir on disk; else base
     *   header `default`/`base`/''      → base user/config (Admin2's "Default")
     *   header naming an env            → that env (existence checked by writeDir())
     *   header with an invalid name     → ValidationException
     */
    public function targetFor(ServerRequestInterface $request): ?string
    {
        if (!$request->hasHeader('X-Config-Environment')) {
            return (new EnvironmentService($this->grav))->activeEnvironment();
        }

        $name = trim($request->getHeaderLine('X-Config-Environment'));
        if ($name === '' || EnvironmentService::isReservedName($name)) {
            return null;
        }

        if (!EnvironmentService::isValidName($name)) {
            throw new ValidationException("Invalid X-Config-Environment header: '{$name}'.");
        }

        return $name;
    }

    /**
     * Absolute config dir for a target: base user/config, or the env's existing
     * config root. An environment is never created here (ValidationException
     * when it doesn't exist); environments are created via POST /system/environments.
     */
    public function writeDir(?string $target): string
    {
        if ($target !== null && $target !== '') {
            $dir = (new EnvironmentService($this->grav))->envConfigRoot($target);
            if ($dir === null) {
                throw new ValidationException("Environment '{$target}' does not exist. Create it first via POST /system/environments.");
            }
            return $dir;
        }

        $userConfig = $this->grav['locator']->findResource('user://config', true);
        if (!$userConfig) {
            throw new \RuntimeException('Base user/config directory not found.');
        }
        return $userConfig;
    }

    /**
     * Absolute yaml path for a scope ('system', 'site', 'plugins/<slug>',
     * 'themes/<slug>', custom scopes) under a target. NotFoundException for an
     * unknown scope.
     */
    public function filePath(string $scope, ?string $target): string
    {
        return $this->writeDir($target) . '/' . $this->scopeFileName($scope);
    }

    /**
     * Effective merged config for a scope as seen from the target, resolved from
     * the YAML layers (plus GRAV_CONFIG__* overrides) rather than the live
     * config, which only ever reflects the environment the request booted under.
     *
     * @return array<mixed>
     */
    public function effective(string $scope, ?string $target): array
    {
        return (new ConfigDiffer($this->grav))->effective($scope, $target);
    }

    /**
     * Persist the full $data for a scope to the target.
     *
     * Values supplied through GRAV_CONFIG__* server variables are stripped, and
     * only the delta against the target's parent (defaults for base; defaults
     * plus base for an environment) is written. No file is created when there is
     * nothing to store. Plugin/theme sub-dirs are created only inside an existing
     * base or env dir. Clears the 'standard' cache, and updates the live config
     * key when the target is the layer the request booted under (base when no
     * environment overlay is active).
     *
     * @param array<mixed> $data
     */
    public function save(string $scope, array $data, ?string $target): void
    {
        $filePath = $this->filePath($scope, $target);

        $differ = new ConfigDiffer($this->grav);
        // Never persist values supplied through GRAV_CONFIG__* env vars (.env);
        // they're re-applied at runtime and writing them would leak secrets to disk.
        $full = $differ->stripEnvironmentOverrides($data, $scope);
        $delta = $differ->diff($full, $differ->parent($scope, $target), $scope);

        // No overrides and no pre-existing file → don't create an empty placeholder.
        if ($delta !== [] || is_file($filePath)) {
            $dir = dirname($filePath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Unable to create directory "%s"', $dir));
            }

            file_put_contents($filePath, Yaml::dump($delta));
        }

        // Compiled config caches the old values until cleared.
        $cache = $this->grav['cache'] ?? null;
        if (is_object($cache) && method_exists($cache, 'clearCache')) {
            $cache->clearCache('standard');
        }

        $config = $this->grav['config'] ?? null;
        if (is_object($config) && $target === (new EnvironmentService($this->grav))->activeEnvironment()) {
            $config->set($this->configKey($scope), $data);
        }
    }

    /**
     * Filename for a scope, relative to a config directory.
     */
    private function scopeFileName(string $scope): string
    {
        return match (true) {
            in_array($scope, ConfigScopes::CORE, true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            ConfigScopes::isCustom($this->grav, $scope) => $scope . '.yaml',
            default => throw new NotFoundException("Unknown configuration scope '{$scope}'."),
        };
    }

    /**
     * Live config key for a scope ('plugins/foo' → 'plugins.foo').
     */
    private function configKey(string $scope): string
    {
        return match (true) {
            str_starts_with($scope, 'plugins/') => 'plugins.' . substr($scope, 8),
            str_starts_with($scope, 'themes/') => 'themes.' . substr($scope, 7),
            default => $scope,
        };
    }
}
