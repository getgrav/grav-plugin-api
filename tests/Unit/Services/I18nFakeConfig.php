<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

class I18nFakeConfig
{
    /** @param array<string, bool> $enabledPlugins */
    public function __construct(
        private readonly array $enabledPlugins,
        private readonly string $activeTheme,
        private readonly array $extra = [],
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === 'system.pages.theme') {
            return $this->activeTheme;
        }
        if (preg_match('/^plugins\.([^.]+)\.enabled$/', $key, $m)) {
            return $this->enabledPlugins[$m[1]] ?? $default;
        }

        return $this->extra[$key] ?? $default;
    }
}

/**
 * Always a miss, so every test exercises the real index build rather than a
 * value cached by a sibling test.
 */
