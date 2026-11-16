<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Router;

use Grav\Plugin\Api\ApiRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The session's `Pragma: no-cache` is dropped only from responses that ask
 * to be kept for a while; every other response keeps it.
 */
#[CoversClass(ApiRouter::class)]
class SessionPragmaTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function cacheControls(): array
    {
        return [
            'immutable module' => ['public, max-age=31536000, immutable', true],
            'privatized by core' => ['private, immutable, max-age=31536000', true],
            'thumbnail' => ['private, max-age=600', true],
            'quoted max-age' => ['max-age="60"', true],
            'revalidate each time' => ['no-cache', false],
            'private no-cache' => ['private, no-cache', false],
            'no-store' => ['no-store, max-age=0', false],
            'max-age zero' => ['max-age=0', false],
            'max-age with no-cache' => ['max-age=600, no-cache', false],
            'none' => ['', false],
            's-maxage only' => ['s-maxage=600', false],
        ];
    }

    #[Test]
    #[DataProvider('cacheControls')]
    public function only_a_response_with_its_own_lifetime_is_cacheable(string $cacheControl, bool $expected): void
    {
        self::assertSame($expected, ApiRouter::cacheableFor($cacheControl));
    }
}
