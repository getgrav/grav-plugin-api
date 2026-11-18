<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\SchedulerController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GET /systeminfo reports the cache driver Grav is really using next to the
 * configured one, and flags a fallback when the configured driver was
 * unavailable (grav-plugin-admin2#217).
 */
#[CoversClass(SchedulerController::class)]
class SchedulerSystemInfoCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
     */
    public static function drivers(): array
    {
        return [
            'auto picks apcu' => ['auto', 'apcu', false, false],
            'auto picks file' => ['auto', 'file', false, false],
            'redis works' => ['redis', 'redis', false, false],
            'redis down falls back' => ['redis', 'file', false, true],
            'memcached missing falls back' => ['memcached', 'file', false, true],
            'legacy memcache name' => ['memcache', 'memcached', false, false],
            'legacy filesystem name' => ['filesystem', 'file', false, false],
            'apcu not loaded falls back' => ['apcu', 'file', false, true],
            'cli compatibility swaps apcu on purpose' => ['apcu', 'file', true, false],
            'cli compatibility does not excuse redis' => ['redis', 'file', true, true],
        ];
    }

    #[Test]
    #[DataProvider('drivers')]
    public function reports_active_driver_and_fallback(string $configured, string $active, bool $cliCompat, bool $fallback): void
    {
        $cache = $this->systemInfo($configured, $cliCompat, $active)['cache'];

        self::assertSame($configured, $cache['driver']);
        self::assertSame($active, $cache['active']);
        self::assertSame($fallback, $cache['fallback']);
    }

    #[Test]
    public function without_a_cache_service_active_is_null(): void
    {
        $cache = $this->systemInfo('redis', false, null)['cache'];

        self::assertSame('redis', $cache['driver']);
        self::assertNull($cache['active']);
        self::assertFalse($cache['fallback']);
    }

    /**
     * @return array<string, mixed>
     */
    private function systemInfo(string $driver, bool $cliCompat, ?string $active): array
    {
        $services = [
            'plugins' => new class {
                /** @return array<string, mixed> */
                public function all(): array
                {
                    return [];
                }
            },
            'config' => new class ($driver, $cliCompat) {
                public function __construct(private string $driver, private bool $cliCompat) {}

                public function get(string $key, mixed $default = null): mixed
                {
                    return match ($key) {
                        'system.cache.driver' => $this->driver,
                        'system.cache.enabled' => true,
                        'system.cache.cli_compatibility' => $this->cliCompat,
                        default => $default,
                    };
                }
            },
        ];
        if ($active !== null) {
            $services['cache'] = new class ($active) {
                public function __construct(private string $name) {}

                public function getDriverName(): string
                {
                    return $this->name;
                }
            };
        }

        $controller = new SchedulerController(TestHelper::createMockGrav($services), new Config());
        $response = $controller->systemInfo(TestHelper::createMockRequest('GET', '/systeminfo', attributes: [
            'api_user' => TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]),
        ]));

        $body = json_decode((string) $response->getBody(), true);

        return $body['data'];
    }
}
