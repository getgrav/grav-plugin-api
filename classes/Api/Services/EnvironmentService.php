<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * Resolves environment folders for config writes.
 *
 * The base write target is always user/config/. Named environments live in
 * user/env/<name>/ (preferred) or legacy user/<name>/ layouts from Grav 1.6.
 * We never auto-create env folders — they must be opted into via the
 * environments API.
 */
class EnvironmentService
{
    private const RESERVED_USER_DIRS = [
        'accounts', 'blueprints', 'config', 'data', 'env',
        'images', 'languages', 'media', 'pages', 'plugins', 'themes',
    ];

    public function __construct(private Grav $grav)
    {
    }

    /**
     * Absolute path to an env's config dir, or null if it doesn't exist.
     * Checks user/env/<name>/config first, then legacy user/<name>/config.
     */
    public function envConfigRoot(string $name): ?string
    {
        $userRoot = $this->userRoot();
        if ($userRoot === null) return null;

        foreach ([
            $userRoot . '/env/' . $name . '/config',
            $userRoot . '/' . $name . '/config',
        ] as $dir) {
            if (is_dir($dir)) return $dir;
        }
        return null;
    }

    /**
     * List existing env folder names — user/env/* plus legacy user/<host>/
     * that have a config/ subdir. Sorted, case-insensitive natural order.
     *
     * @return string[]
     */
    public function listEnvironments(): array
    {
        $names = [];
        $userRoot = $this->userRoot();
        if ($userRoot === null) return $names;

        $envDir = $userRoot . '/env';
        if (is_dir($envDir)) {
            foreach (new \DirectoryIterator($envDir) as $item) {
                if ($item->isDot() || !$item->isDir()) continue;
                $names[$item->getFilename()] = true;
            }
        }

        foreach (new \DirectoryIterator($userRoot) as $item) {
            if ($item->isDot() || !$item->isDir()) continue;
            $n = $item->getFilename();
            if (in_array($n, self::RESERVED_USER_DIRS, true) || str_starts_with($n, '.')) continue;
            if (is_dir($item->getPathname() . '/config')) {
                $names[$n] = true;
            }
        }

        $names = array_keys($names);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    public function envHasOverrides(string $name): bool
    {
        $root = $this->envConfigRoot($name);
        if ($root === null) return false;
        foreach (new \FilesystemIterator($root) as $_) {
            return true;
        }
        return false;
    }

    /**
     * Create a new env/<name>/config/ folder. Returns the created config dir.
     * Throws \InvalidArgumentException on invalid names and \RuntimeException on fs failure.
     */
    public function createEnvironment(string $name): string
    {
        if (!self::isValidName($name)) {
            throw new \InvalidArgumentException("Invalid environment name '{$name}'.");
        }
        if (in_array($name, $this->listEnvironments(), true)) {
            throw new \InvalidArgumentException("Environment '{$name}' already exists.");
        }

        $userRoot = $this->userRoot();
        if ($userRoot === null) {
            throw new \RuntimeException('user:// path not resolvable.');
        }

        $configDir = $userRoot . '/env/' . $name . '/config';
        if (!mkdir($configDir, 0775, true) && !is_dir($configDir)) {
            throw new \RuntimeException("Failed to create environment directory: {$configDir}");
        }
        return $configDir;
    }

    public static function isValidName(string $name): bool
    {
        return $name !== '' && (bool)preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name);
    }

    private function userRoot(): ?string
    {
        $root = $this->grav['locator']->findResource('user://', true);
        return $root !== false && is_string($root) ? $root : null;
    }
}
