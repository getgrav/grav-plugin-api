<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\ApiRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The route cache's identity: the enabled plugin set, plus when each plugin's
 * blueprints.yaml last changed, so an upgrade rebuilds the table without a
 * manual cache clear.
 */
#[CoversClass(ApiRouter::class)]
final class ApiRouterFingerprintTest extends TestCase
{
    /** @param array<string, array<string, mixed>> $plugins */
    private static function config(array $plugins): Config
    {
        return new Config(['plugins' => $plugins]);
    }

    #[Test]
    public function it_is_stable_for_the_same_set_and_the_same_blueprints(): void
    {
        $mtime = static fn (string $slug): int => $slug === 'shop' ? 1000 : 2000;

        $first = ApiRouter::routeSetFingerprint(self::config(['shop' => [], 'quiet' => []]), $mtime);
        $again = ApiRouter::routeSetFingerprint(self::config(['shop' => [], 'quiet' => []]), $mtime);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
        self::assertSame($first, $again);
    }

    #[Test]
    public function it_moves_when_a_plugin_is_disabled(): void
    {
        $mtime = static fn (string $slug): int => 1000;

        $all = ApiRouter::routeSetFingerprint(self::config(['shop' => [], 'quiet' => []]), $mtime);
        $fewer = ApiRouter::routeSetFingerprint(self::config(['shop' => [], 'quiet' => ['enabled' => false]]), $mtime);

        self::assertNotSame($all, $fewer);
    }

    #[Test]
    public function it_moves_when_a_plugin_is_upgraded(): void
    {
        $config = self::config(['shop' => [], 'quiet' => []]);

        $before = ApiRouter::routeSetFingerprint($config, static fn (string $slug): int => 1000);
        $after = ApiRouter::routeSetFingerprint($config, static fn (string $slug): int => $slug === 'shop' ? 1001 : 1000);

        self::assertNotSame($before, $after);
    }

    #[Test]
    public function a_plugin_with_no_blueprint_still_counts(): void
    {
        $mtime = static fn (string $slug): int => 0;

        $with = ApiRouter::routeSetFingerprint(self::config(['shop' => []]), $mtime);
        $without = ApiRouter::routeSetFingerprint(self::config([]), $mtime);

        self::assertNotSame($with, $without);
    }
}
