<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

class I18nFakeLanguage
{
    public function __construct(private readonly ?string $active = null)
    {
    }

    public function getActive(): ?string
    {
        return $this->active;
    }
}

/**
 * Minimal stand-in for the compiled Languages object: records what was merged
 * so a test can assert the runtime override actually reached it.
 */
