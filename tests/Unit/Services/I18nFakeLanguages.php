<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

/**
 * Minimal stand-in for the compiled Languages object: records what was merged
 * so a test can assert the runtime override actually reached it.
 */
class I18nFakeLanguages
{
    /** @var array<int, array<mixed>> */
    public array $merged = [];

    public function mergeRecursive(array $data): void
    {
        $this->merged[] = $data;
    }
}
