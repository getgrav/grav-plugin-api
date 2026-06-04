<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Services\EnvironmentService;

/**
 * Differential config-save support.
 *
 * Admin writes should only persist values that actually override the parent —
 * matching how developers hand-edit Grav configs. The parent of each config
 * scope is:
 *
 *   system / site / media / security / scheduler / backups
 *     → system/config/<scope>.yaml  (Grav core defaults)
 *
 *   plugins/<name>
 *     → user/plugins/<name>/<name>.yaml  (plugin's own defaults)
 *
 *   themes/<name>
 *     → user/themes/<name>/<name>.yaml  (theme's own defaults)
 *
 * For env-targeted writes the parent is defaults merged with the current
 * user/config/<scope>.yaml, so env files store only values that differ from
 * the effective base config.
 *
 * Note: we deliberately use the raw YAML files as the source of defaults, not
 * blueprint defaults. Blueprints describe the admin form; they can diverge
 * from what the yaml actually supplies at load time.
 */
class ConfigDiffer
{
    private const CORE_SCOPES = ['system', 'site', 'media', 'security', 'scheduler', 'backups'];

    public function __construct(private Grav $grav)
    {
    }

    /**
     * Return the subset of $current that differs from $parent.
     *
     * Associative arrays recurse; sequential arrays are treated as atomic
     * values (any difference → the whole new list is retained). This avoids
     * the classic admin-classic trap where shortening a list silently merged
     * removed entries back in.
     *
     * @param array<mixed> $current
     * @param array<mixed> $parent
     * @return array<mixed>
     */
    public function diff(array $current, array $parent): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            if (!array_key_exists($key, $parent)) {
                $out[$key] = $value;
                continue;
            }

            $parentValue = $parent[$key];

            if (self::valuesEqual($value, $parentValue)) {
                continue;
            }

            if (is_array($value) && is_array($parentValue)
                && self::isAssoc($value) && self::isAssoc($parentValue)) {
                $sub = $this->diff($value, $parentValue);
                if ($sub !== []) {
                    $out[$key] = $sub;
                }
                continue;
            }

            // Scalar change, sequential-array change, or shape change (assoc↔list).
            $out[$key] = $value;
        }
        return $out;
    }

    /**
     * Parent config for a scope + optional env target.
     * See class docblock for parent resolution rules.
     *
     * @return array<mixed>
     */
    public function parent(string $scope, ?string $targetEnv): array
    {
        $defaults = $this->loadYamlAtPath($this->defaultsPath($scope)) ?? [];
        if ($targetEnv === null || $targetEnv === '') {
            return $defaults;
        }

        $base = $this->loadYamlAtPath($this->baseFilePath($scope)) ?? [];
        if ($base === []) return $defaults;

        return $this->deepMergeAssoc($defaults, $base);
    }

    /**
     * The effective merged config for $scope under $targetEnv, computed purely
     * from YAML files:
     *
     *   defaults ⊕ user/config ⊕ user/env/<targetEnv>/config (when targetEnv set)
     *
     * then with GRAV_CONFIG__* environment overrides re-applied so the result
     * matches what Grav resolves at runtime. Used as the baseline the admin
     * reads and edits when the requested target differs from the environment
     * Grav booted under — notably base/"Default" while a hostname overlay is
     * active. Grav can't re-resolve its environment mid-request, so we resolve
     * the files ourselves; this is what stops "Default" from showing — and a
     * save from inheriting — the env overlay.
     *
     * @return array<mixed>
     */
    public function effective(string $scope, ?string $targetEnv): array
    {
        $merged = $this->loadYamlAtPath($this->defaultsPath($scope)) ?? [];

        $base = $this->loadYamlAtPath($this->baseFilePath($scope)) ?? [];
        if ($base !== []) {
            $merged = $this->deepMergeAssoc($merged, $base);
        }

        if ($targetEnv !== null && $targetEnv !== '') {
            $overlay = $this->loadYamlAtPath($this->envFilePath($scope, $targetEnv)) ?? [];
            if ($overlay !== []) {
                $merged = $this->deepMergeAssoc($merged, $overlay);
            }
        }

        return $this->applyEnvironmentOverrides($merged, $scope);
    }

    /**
     * Re-apply GRAV_CONFIG__* overrides for $scope on top of $data, mirroring
     * the runtime layering Grav core does (InitializeProcessor), so a file-based
     * effective() shows the same value Grav serves. Values are read from the
     * live config — env-var overrides are environment-agnostic, so they apply
     * identically regardless of the target. The inverse of
     * stripEnvironmentOverrides(), which removes these on save.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function applyEnvironmentOverrides(array $data, string $scope): array
    {
        $envKeys = $this->environmentOverrideKeys();
        if ($envKeys === [] || $scope === '') {
            return $data;
        }

        $prefix = str_replace('/', '.', $scope);
        $config = $this->grav['config'] ?? null;

        foreach ($envKeys as $key) {
            $isWholeScope = $key === $prefix;
            if (!$isWholeScope && !str_starts_with($key, $prefix . '.')) {
                continue;
            }
            $value = is_object($config) && method_exists($config, 'get') ? $config->get($key) : null;
            if ($value === null) {
                continue;
            }
            if ($isWholeScope) {
                return is_array($value) ? $value : $data;
            }
            $data = $this->setDotPath($data, substr($key, strlen($prefix) + 1), $value);
        }

        return $data;
    }

    /**
     * Set a dotted path in a nested array, creating intermediate maps. The
     * counterpart to unsetDotPath().
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function setDotPath(array $data, string $path, mixed $value): array
    {
        $parts = explode('.', $path);
        $ref = &$data;
        foreach ($parts as $i => $part) {
            if ($i === array_key_last($parts)) {
                $ref[$part] = $value;
                break;
            }
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        unset($ref);
        return $data;
    }

    /**
     * Remove from $data any values that are currently supplied by GRAV_CONFIG__*
     * environment variables for this scope, pruning subtrees that empty out.
     *
     * Those overrides are layered onto the compiled config at runtime by Grav
     * core (InitializeProcessor) and always win, so they must never be written
     * back to a YAML file on save — doing so would persist a secret provided
     * through `.env` (or the server environment) into the config on disk. This
     * is scope-agnostic: it works for system/site/plugins/themes and any other
     * config namespace because a scope maps to its config key by turning the
     * `/` separator into a `.`.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function stripEnvironmentOverrides(array $data, string $scope): array
    {
        $envKeys = $this->environmentOverrideKeys();
        if ($envKeys === [] || $scope === '') {
            return $data;
        }

        $prefix = str_replace('/', '.', $scope);

        foreach ($envKeys as $key) {
            if ($key === $prefix) {
                // The entire scope is provided by the environment.
                return [];
            }
            if (str_starts_with($key, $prefix . '.')) {
                $data = $this->unsetDotPath($data, substr($key, strlen($prefix) + 1));
            }
        }

        return $data;
    }

    /**
     * Dotted config keys currently supplied via GRAV_CONFIG__* environment
     * variables, with GRAV_CONFIG_ALIAS__ substitution applied. Mirrors the
     * resolution in Grav core's InitializeProcessor::initializeConfig() so the
     * keys we skip on save are exactly the keys core injects at runtime. Empty
     * when the GRAV_CONFIG switch is off.
     *
     * @return list<string>
     */
    public function environmentOverrideKeys(): array
    {
        if (!getenv('GRAV_CONFIG')) {
            return [];
        }

        $prefix = 'GRAV_CONFIG';
        $cPrefix = $prefix . '__';
        $aPrefix = $prefix . '_ALIAS__';
        $cLen = strlen($cPrefix);
        $aLen = strlen($aPrefix);

        $keys = [];
        $aliases = [];
        foreach ($_ENV + $_SERVER as $name => $value) {
            $name = (string) $name;
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            if (str_starts_with($name, $cPrefix)) {
                $keys[] = str_replace('__', '.', substr($name, $cLen));
            } elseif (str_starts_with($name, $aPrefix)) {
                $aliases[substr($name, $aLen)] = (string) $value;
            }
        }

        foreach ($keys as $i => $key) {
            foreach ($aliases as $alias => $real) {
                $key = str_replace($alias, $real, $key);
            }
            $keys[$i] = $key;
        }

        return $keys;
    }

    /**
     * Flatten a nested config delta to its dotted leaf paths. A "leaf" is a
     * scalar, a sequential (list) array — treated atomically, matching diff() —
     * or an empty array; only associative maps recurse. Used to map a persisted
     * override delta onto blueprint field names for the override indicators.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function flattenLeaves(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value) && self::isAssoc($value)) {
                $out = array_merge($out, self::flattenLeaves($value, $path));
            } else {
                $out[] = $path;
            }
        }
        return $out;
    }

    /**
     * Dig a dotted path out of a nested array, or null if any segment is
     * missing. Callers treat "absent in the parent" as "reverts to the
     * blueprint default / unset".
     *
     * @param array<mixed> $data
     */
    public static function valueAtPath(array $data, string $path): mixed
    {
        $ref = $data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($ref) || !array_key_exists($part, $ref)) {
                return null;
            }
            $ref = $ref[$part];
        }
        return $ref;
    }

    /**
     * Unset a dotted path from a nested array, pruning parents left empty.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function unsetDotPath(array $data, string $path): array
    {
        $parts = explode('.', $path);
        $key = array_shift($parts);

        if (!array_key_exists($key, $data)) {
            return $data;
        }

        if ($parts === []) {
            unset($data[$key]);
            return $data;
        }

        if (is_array($data[$key])) {
            $data[$key] = $this->unsetDotPath($data[$key], implode('.', $parts));
            if ($data[$key] === []) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Recursive merge: $override wins, assoc subtrees recurse, sequential
     * arrays are REPLACED (not concatenated).
     *
     * @param array<mixed> $base
     * @param array<mixed> $override
     * @return array<mixed>
     */
    public function deepMergeAssoc(array $base, array $override): array
    {
        foreach ($override as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])
                && self::isAssoc($v) && self::isAssoc($base[$k])) {
                $base[$k] = $this->deepMergeAssoc($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /**
     * Path to the defaults file for $scope, or null if none resolvable.
     */
    private function defaultsPath(string $scope): ?string
    {
        $locator = $this->grav['locator'];

        if (in_array($scope, self::CORE_SCOPES, true)) {
            $p = $locator->findResource('system://config/' . $scope . '.yaml', true);
            return $p ?: null;
        }
        if (str_starts_with($scope, 'plugins/')) {
            $name = substr($scope, 8);
            $p = $locator->findResource('plugins://' . $name . '/' . $name . '.yaml', true);
            return $p ?: null;
        }
        if (str_starts_with($scope, 'themes/')) {
            $name = substr($scope, 7);
            $p = $locator->findResource('themes://' . $name . '/' . $name . '.yaml', true);
            return $p ?: null;
        }
        return null;
    }

    /**
     * Path to the base user/config file for $scope, or null if missing.
     */
    private function baseFilePath(string $scope): ?string
    {
        $userConfig = $this->grav['locator']->findResource('user://config', true);
        if (!$userConfig) return null;

        $relative = $this->scopeRelativeFile($scope);
        if ($relative === null) return null;

        $full = $userConfig . '/' . $relative;
        return is_file($full) ? $full : null;
    }

    /**
     * Path to an env overlay file for $scope under $targetEnv, or null if the
     * env (or file) doesn't exist. Resolves user/env/<env>/config first, then
     * the legacy user/<env>/config layout — same as EnvironmentService.
     */
    private function envFilePath(string $scope, string $targetEnv): ?string
    {
        $root = (new EnvironmentService($this->grav))->envConfigRoot($targetEnv);
        if ($root === null) return null;

        $relative = $this->scopeRelativeFile($scope);
        if ($relative === null) return null;

        $full = $root . '/' . $relative;
        return is_file($full) ? $full : null;
    }

    /**
     * The config filename for $scope relative to a config dir
     * (e.g. 'system.yaml', 'plugins/foo.yaml'), or null for unknown scopes.
     */
    private function scopeRelativeFile(string $scope): ?string
    {
        return match (true) {
            in_array($scope, self::CORE_SCOPES, true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            // Site-authored top-level config: a flat user/config/<scope>.yaml,
            // so base + env overlay reads resolve like the core scopes.
            ConfigScopes::isCustom($this->grav, $scope) => $scope . '.yaml',
            default => null,
        };
    }

    /**
     * @return array<mixed>|null
     */
    private function loadYamlAtPath(?string $path): ?array
    {
        if ($path === null || !is_file($path)) return null;
        try {
            $content = Yaml::parse((string)file_get_contents($path));
        } catch (\Throwable) {
            return null;
        }
        return is_array($content) ? $content : null;
    }

    /**
     * @param array<mixed> $arr
     */
    public static function isAssoc(array $arr): bool
    {
        if ($arr === []) return false;
        return !array_is_list($arr);
    }

    /**
     * Deep value equality with canonical key order for associative arrays so
     * the same logical config hashes equal regardless of key insertion order.
     */
    public static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            return self::canonicalize($a) === self::canonicalize($b);
        }
        return $a === $b;
    }

    /**
     * Recursively sort associative arrays by key so the same logical config
     * serializes (and therefore hashes) identically regardless of key order.
     * Sequential arrays keep their order.
     *
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public static function canonicalize(array $arr): array
    {
        if (self::isAssoc($arr)) {
            ksort($arr);
        }
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = self::canonicalize($v);
            }
        }
        return $arr;
    }
}
