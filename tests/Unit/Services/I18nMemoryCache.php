<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

/**
 * A cache that remembers, for tests that exercise what is reused between
 * requests. Share one instance across the index objects standing in for
 * separate requests.
 */
class I18nMemoryCache
{
    /** @var array<string, mixed> */
    public array $saved = [];

    public function fetch(string $key): mixed
    {
        return $this->saved[$key] ?? false;
    }

    public function save(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->saved[$key] = $value;

        return true;
    }

    /** @return array<int, string> */
    public function keysStartingWith(string $prefix): array
    {
        return array_values(array_filter(array_keys($this->saved), static fn(string $k): bool => str_starts_with($k, $prefix)));
    }
}
