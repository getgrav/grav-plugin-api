<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Yaml;

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

        $relative = match (true) {
            in_array($scope, self::CORE_SCOPES, true) => $scope . '.yaml',
            str_starts_with($scope, 'plugins/') => 'plugins/' . substr($scope, 8) . '.yaml',
            str_starts_with($scope, 'themes/') => 'themes/' . substr($scope, 7) . '.yaml',
            default => null,
        };
        if ($relative === null) return null;

        $full = $userConfig . '/' . $relative;
        return is_file($full) ? $full : null;
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
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    private static function canonicalize(array $arr): array
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
