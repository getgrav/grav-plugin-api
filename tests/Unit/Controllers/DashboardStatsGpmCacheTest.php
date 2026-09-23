<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\DashboardController;
use Grav\Plugin\Api\Tests\Unit\Services\I18nTestFixture;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `/dashboard/stats` must never download the GPM repositories. `new GPM(false)`
 * does exactly that whenever its cached copy is missing (every cache clear), so
 * the controller checks for the cached repository data first and reports the
 * update figures as unknown (null) when it is not there.
 */
#[CoversClass(DashboardController::class)]
class DashboardStatsGpmCacheTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-api-gpm-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        // The cache adapter lays out its own folders under gpm/.
        I18nTestFixture::rrmdir($this->tmp);
    }

    private function counts(string $gpmDir): ?array
    {
        $config = new Config([]);
        $locator = new class ($gpmDir) {
            public function __construct(private string $dir) {}
            public function findResource(string $uri, bool $absolute = false, bool $first = false): string|false
            {
                return $uri === 'cache://gpm' ? $this->dir : false;
            }
            /** @return array<int, string> */
            public function findResources(string $uri): array
            {
                return [];
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);
        $controller = new DashboardController($grav, $config);

        return (new ReflectionMethod($controller, 'gpmUpdateCounts'))->invoke($controller, 'quark');
    }

    #[Test]
    public function missing_gpm_cache_folder_means_unknown(): void
    {
        $this->assertNull($this->counts($this->tmp . '/gpm'));
    }

    #[Test]
    public function empty_gpm_cache_means_unknown_without_fetching(): void
    {
        mkdir($this->tmp . '/gpm');

        $this->assertNull($this->counts($this->tmp . '/gpm'));
    }

    #[Test]
    public function repository_urls_come_from_the_gpm_classes(): void
    {
        $url = new ReflectionMethod(DashboardController::class, 'remoteRepositoryUrl');

        $this->assertStringEndsWith('/plugins.json', (string) $url->invoke(null, \Grav\Common\GPM\Remote\Plugins::class));
        $this->assertStringEndsWith('/themes.json', (string) $url->invoke(null, \Grav\Common\GPM\Remote\Themes::class));
        $this->assertStringEndsWith('/grav.json', (string) $url->invoke(null, \Grav\Common\GPM\Remote\GravCore::class));
    }
}
