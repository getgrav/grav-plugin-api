<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaMetadataTextTest extends TestCase
{
    public static function templateText(): array
    {
        return [
            ['{{ value }}', 'text'],
            ['{% set value = 1 %}', 'textarea'],
            ['{# note #}', 'text'],
            ['{{ value }}', 'select'],
            ['{<b></b>{ value }}', 'text'],
            ["{\x01{ value }}", 'textarea'],
        ];
    }

    #[DataProvider('templateText')]
    public function testMetadataTextMustBeLiteral(string $value, string $type): void
    {
        $controller = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $this->expectException(ValidationException::class);
        (new \ReflectionMethod($controller, 'sanitizeMetadataValue'))->invoke($controller, $value, $type, 2000, [$value => 'Label']);
    }
}
