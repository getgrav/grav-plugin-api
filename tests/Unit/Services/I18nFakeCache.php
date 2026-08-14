<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

/**
 * Always a miss, so every test exercises the real index build rather than a
 * value cached by a sibling test.
 */
class I18nFakeCache
{
    /** @var array<string, mixed> */
    public array $saved = [];

    public function fetch(string $key): mixed
    {
        return false;
    }

    public function save(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->saved[$key] = $value;

        return true;
    }
}
