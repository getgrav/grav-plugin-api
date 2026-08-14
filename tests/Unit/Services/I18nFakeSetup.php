<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

class I18nFakeSetup
{
    public function __construct(public string $environment = 'localhost')
    {
    }
}
