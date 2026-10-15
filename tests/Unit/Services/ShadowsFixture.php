<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Config\Config;
use Grav\Common\Config\Setup;
use Grav\Common\Grav;

/**
 * A throwaway site on disk for the shadowed-config tests: core defaults, base
 * `user/config`, `user/env/<host>/config` overlays and a plugin folder, wired
 * into a fresh Grav instance through a path-mapping locator. Also sets and
 * clears GRAV_CONFIG__* server variables and the booted environment.
 */
final class ShadowsFixture
{
    public readonly string $root;

    private ?string $savedEnvironment;

    /** @var list<string> */
    private array $vars = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/grav-shadows-' . bin2hex(random_bytes(4));
        foreach (['system/config', 'user/config/plugins', 'user/plugins', 'user/env'] as $dir) {
            mkdir($this->root . '/' . $dir, 0777, true);
        }
        $this->savedEnvironment = Setup::$environment;
        Setup::$environment = null;
    }

    public function write(string $relative, string $yaml): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $yaml);
    }

    /** Boot under $name, the way Grav records the hostname environment. */
    public function bootEnvironment(?string $name): void
    {
        Setup::$environment = $name;
    }

    public function serverVar(string $name, string $value): void
    {
        foreach (['GRAV_CONFIG', $name] as $var) {
            putenv($var . '=' . ($var === 'GRAV_CONFIG' ? 'true' : $value));
            $_ENV[$var] = $var === 'GRAV_CONFIG' ? 'true' : $value;
            $this->vars[] = $var;
        }
    }

    /**
     * @param array<string, mixed> $config Live config, as Grav booted it.
     */
    public function grav(array $config = []): Grav
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $root = $this->root;
        $grav['locator'] = new class ($root) {
            public function __construct(private readonly string $root) {}

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                $map = [
                    'user://config' => $this->root . '/user/config',
                    'system://config' => $this->root . '/system/config',
                    'plugins://' => $this->root . '/user/plugins',
                    'plugin://' => $this->root . '/user/plugins',
                    'user://' => $this->root . '/user',
                ];
                foreach ($map as $prefix => $base) {
                    if ($uri === $prefix) {
                        return is_dir($base) || $first ? $base : false;
                    }
                    if (str_starts_with($uri, $prefix)) {
                        $sub = ltrim(substr($uri, strlen($prefix)), '/');
                        $full = $base . ($sub !== '' ? '/' . $sub : '');
                        return file_exists($full) ? $full : false;
                    }
                }
                return false;
            }

            public function isStream(string $uri): bool
            {
                return false;
            }

            /** @return array<int, string> */
            public function findResources(string $uri, bool $absolute = true, bool $all = false): array
            {
                return [];
            }
        };
        $grav['cache'] = new class {
            public function clearCache(string $remove = 'standard'): array
            {
                return [];
            }
        };
        $grav['plugins'] = new class {
            public mixed $formFieldTypes = null;
        };
        $grav['config'] = new Config($config);

        return $grav;
    }

    public function cleanup(): void
    {
        Setup::$environment = $this->savedEnvironment;
        foreach (array_unique($this->vars) as $var) {
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }
        if (is_dir($this->root)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->root);
        }
        Grav::resetInstance();
    }
}
