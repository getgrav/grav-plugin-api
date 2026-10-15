<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Data\Blueprint;
use Grav\Common\Grav;
use Grav\Common\Yaml;

/**
 * Config values a layer ABOVE the write target decides, so saving them to the
 * target changes nothing the site actually uses.
 *
 * Two layers can do that:
 *
 *   environment  the env overlay Grav booted with (e.g. `user/env/<host>/`),
 *                which wins over base `user/config`. Only relevant when the
 *                write target is base: a save to the env itself, or to some
 *                other env, is not hidden by it.
 *   server       `GRAV_CONFIG__*` environment variables, which win over every
 *                file layer whatever the target.
 *
 * This is the opposite direction to `meta.overrides`, which lists what the
 * target's own file overrides relative to the layer beneath. It exists because
 * a site with `enabled: false` in its hostname overlay could enable the plugin
 * under "Default", be told the save worked, and still never load it
 * (getgrav/grav-plugin-sync-mercure#1).
 *
 * One instance caches the active environment and the GRAV_CONFIG keys, so a
 * plugin listing resolves them once rather than once per plugin.
 */
class ConfigShadows
{
    private ConfigDiffer $differ;

    private bool $activeResolved = false;
    private ?string $active = null;
    private ?string $activeRoot = null;

    /** @var list<string>|null */
    private ?array $serverKeys = null;

    public function __construct(private Grav $grav)
    {
        $this->differ = new ConfigDiffer($grav);
    }

    /**
     * Every leaf of $scope that a layer above $targetEnv sets, with the value
     * the site actually uses (secrets masked against $blueprint).
     *
     * @return list<array{path: string, source: string, environment: ?string, file: ?string, value: mixed}>
     */
    public function forScope(string $scope, ?string $targetEnv, ?Blueprint $blueprint = null): array
    {
        $server = $this->serverValues($scope);

        $entries = [];
        $active = $this->activeEnvironment();
        if (($targetEnv === null || $targetEnv === '') && $active !== null) {
            $file = $this->differ->envFilePath($scope, $active);
            $data = $this->loadYaml($file);
            if ($file !== null && $data !== []) {
                $relative = $this->relativePath($file);
                foreach (ConfigDiffer::flattenLeaves($data) as $path) {
                    // A server variable wins over the env file, so it is the
                    // one listed for this path.
                    if ($this->isUnderAny($path, array_keys($server))) {
                        continue;
                    }
                    $entries[] = [
                        'path' => $path,
                        'source' => 'environment',
                        'environment' => $active,
                        'file' => $relative,
                        'value' => ConfigDiffer::valueAtPath($data, $path),
                    ];
                }
            }
        }

        foreach ($server as $path => $value) {
            $entries[] = [
                'path' => (string) $path,
                'source' => 'server',
                'environment' => null,
                'file' => null,
                'value' => $value,
            ];
        }

        foreach ($entries as $i => $entry) {
            $entries[$i]['value'] = $this->mask($entry['path'], $entry['value'], $blueprint);
        }

        return $entries;
    }

    /**
     * Where an installed plugin's live `enabled` comes from when it is not base
     * config: a server variable, the booted environment's file, or null.
     *
     * @return array{source: string, environment: ?string, file: ?string}|null
     */
    public function pluginEnabledSource(string $slug): ?array
    {
        if ($slug === '') {
            return null;
        }

        $key = 'plugins.' . $slug . '.enabled';
        $config = $this->grav['config'] ?? null;
        foreach ($this->serverKeys() as $serverKey) {
            if (($serverKey === $key || str_starts_with($key, $serverKey . '.'))
                && is_object($config) && method_exists($config, 'get')
                && $config->get($serverKey) !== null) {
                return ['source' => 'server', 'environment' => null, 'file' => null];
            }
        }

        $active = $this->activeEnvironment();
        if ($active === null || $this->activeRoot === null) {
            return null;
        }

        $file = $this->activeRoot . '/plugins/' . $slug . '.yaml';
        if (!is_file($file)) {
            return null;
        }
        $data = $this->loadYaml($file);
        if (!array_key_exists('enabled', $data)) {
            return null;
        }

        return ['source' => 'environment', 'environment' => $active, 'file' => $this->relativePath($file)];
    }

    /**
     * GRAV_CONFIG__* keys under $scope, as scope-relative leaf path => the
     * live value. A key naming the whole scope lists every leaf of its value.
     *
     * @return array<string, mixed>
     */
    private function serverValues(string $scope): array
    {
        $prefix = str_replace('/', '.', $scope);
        $config = $this->grav['config'] ?? null;
        if ($prefix === '' || !is_object($config) || !method_exists($config, 'get')) {
            return [];
        }

        $out = [];
        foreach ($this->serverKeys() as $key) {
            if ($key !== $prefix && !str_starts_with($key, $prefix . '.')) {
                continue;
            }
            $value = $config->get($key);
            if ($value === null) {
                continue;
            }
            if ($key === $prefix) {
                if (is_array($value)) {
                    foreach (ConfigDiffer::flattenLeaves($value) as $path) {
                        $out[$path] = ConfigDiffer::valueAtPath($value, $path);
                    }
                }
                continue;
            }
            $out[substr($key, strlen($prefix) + 1)] = $value;
        }

        return $out;
    }

    /** @return list<string> */
    private function serverKeys(): array
    {
        return $this->serverKeys ??= $this->differ->environmentOverrideKeys();
    }

    private function activeEnvironment(): ?string
    {
        if (!$this->activeResolved) {
            $this->activeResolved = true;
            try {
                $service = new EnvironmentService($this->grav);
                $this->active = $service->activeEnvironment();
                $this->activeRoot = $this->active !== null ? $service->envConfigRoot($this->active) : null;
            } catch (\Throwable) {
                $this->active = null;
                $this->activeRoot = null;
            }
        }

        return $this->active;
    }

    /**
     * @param list<string> $ancestors
     */
    private function isUnderAny(string $path, array $ancestors): bool
    {
        foreach ($ancestors as $ancestor) {
            $ancestor = (string) $ancestor;
            if ($path === $ancestor || str_starts_with($path, $ancestor . '.')) {
                return true;
            }
        }
        return false;
    }

    private function mask(string $path, mixed $value, ?Blueprint $blueprint): mixed
    {
        $masked = ConfigSecretMasker::mask(ConfigDiffer::setDotPath([], $path, $value), $blueprint);
        return ConfigDiffer::valueAtPath($masked, $path);
    }

    /**
     * $file relative to the Grav root, the way an admin would find it. A file
     * outside the root (a GRAV_ENVIRONMENTS_PATH elsewhere) keeps its full path.
     */
    private function relativePath(string $file): string
    {
        $real = realpath($file) ?: $file;
        foreach (['GRAV_ROOT', 'GRAV_WEBROOT'] as $constant) {
            if (!defined($constant)) {
                continue;
            }
            $root = rtrim((string) constant($constant), '/\\');
            // As written first (a symlinked user/ folder still reads as part
            // of the site), then with both sides resolved.
            foreach ([[$file, $root], [$real, realpath($root) ?: $root]] as [$path, $base]) {
                if ($base !== '' && str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
                    return substr($path, strlen($base) + 1);
                }
            }
        }
        return $real;
    }

    /** @return array<mixed> */
    private function loadYaml(?string $file): array
    {
        if ($file === null || !is_file($file)) {
            return [];
        }
        try {
            $data = Yaml::parse((string) file_get_contents($file));
        } catch (\Throwable) {
            return [];
        }
        return is_array($data) ? $data : [];
    }
}
