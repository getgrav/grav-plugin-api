<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

/**
 * Resolves the handful of streams the translation services use against a temp
 * root laid out like a real Grav install.
 */
class I18nFakeLocator
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array<int, string> */
    public function findResources(string $uri): array
    {
        $path = match (true) {
            $uri === 'system://languages' => $this->root . '/system/languages',
            $uri === 'user://languages' => $this->root . '/user/languages',
            $uri === 'plugins://' => $this->root . '/user/plugins',
            $uri === 'themes://' => $this->root . '/user/themes',
            default => null,
        };

        return $path !== null && is_dir($path) ? [$path] : [];
    }

    public function findResource(string $uri, bool $absolute = true, bool $create = false): string|false
    {
        $path = match (true) {
            $uri === 'user://languages' => $this->root . '/user/languages',
            $uri === 'user://config' => $this->root . '/user/config',
            $uri === 'cache://compiled/languages' => $this->root . '/cache/compiled/languages',
            default => null,
        };

        if ($path === null) {
            return false;
        }
        if ($create && !is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return is_dir($path) ? $path : false;
    }
}
