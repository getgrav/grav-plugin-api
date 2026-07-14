<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\GPM\GPM;
use Grav\Plugin\Api\Controllers\DashboardController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DashboardController::extractUpdateCounts().
 *
 * The full stats() endpoint reaches into pages/users/media/cache and can't be
 * exercised in isolation, so the GPM-derived badge counts are factored into a
 * pure static helper that this suite drives against a mocked GPM — no Grav boot
 * required. These counts feed the sidebar "updates available" badges.
 */
#[CoversClass(DashboardController::class)]
class DashboardControllerUpdateCountsTest extends TestCase
{
    /**
     * @param array{plugins?: array<string,object>, themes?: array<string,object>} $updatable
     */
    private function makeGpm(array $updatable, ?bool $gravUpdatable): GPM
    {
        // GPM extends Grav\Common\Iterator, whose magic __call trips up
        // createMock()'s auto-doubling of getGrav(); name the methods explicitly.
        $gpm = $this->getMockBuilder(GPM::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getUpdatable', 'getGrav'])
            ->getMock();
        $gpm->method('getUpdatable')->willReturn($updatable);

        if ($gravUpdatable === null) {
            $gpm->method('getGrav')->willReturn(null);
        } else {
            $grav = new class ($gravUpdatable) {
                public function __construct(private bool $updatable) {}
                public function isUpdatable(): bool
                {
                    return $this->updatable;
                }
            };
            $gpm->method('getGrav')->willReturn($grav);
        }

        return $gpm;
    }

    #[Test]
    public function counts_updatable_plugins_and_themes(): void
    {
        $gpm = $this->makeGpm(
            [
                'plugins' => ['api' => (object) [], 'admin2' => (object) [], 'form' => (object) []],
                'themes' => ['quark' => (object) []],
            ],
            false,
        );

        $counts = DashboardController::extractUpdateCounts($gpm);

        $this->assertSame(3, $counts['plugins']);
        $this->assertSame(1, $counts['themes']);
        $this->assertFalse($counts['grav']);
    }

    #[Test]
    public function reports_zero_when_nothing_is_updatable(): void
    {
        $gpm = $this->makeGpm(['plugins' => [], 'themes' => []], false);

        $counts = DashboardController::extractUpdateCounts($gpm);

        $this->assertSame(0, $counts['plugins']);
        $this->assertSame(0, $counts['themes']);
        $this->assertFalse($counts['grav']);
    }

    #[Test]
    public function tolerates_missing_plugin_and_theme_keys(): void
    {
        // A cold/empty GPM cache can omit the keys entirely rather than
        // returning empty arrays — the counts must still resolve to zero.
        $gpm = $this->makeGpm([], null);

        $counts = DashboardController::extractUpdateCounts($gpm);

        $this->assertSame(0, $counts['plugins']);
        $this->assertSame(0, $counts['themes']);
        $this->assertFalse($counts['grav']);
    }

    #[Test]
    public function flags_grav_core_update(): void
    {
        $gpm = $this->makeGpm(['plugins' => [], 'themes' => []], true);

        $counts = DashboardController::extractUpdateCounts($gpm);

        $this->assertTrue($counts['grav']);
    }

    #[Test]
    public function flags_active_theme_only_when_the_active_theme_is_updatable(): void
    {
        // DeliverNext (a non-active theme) has the update; the active theme
        // (quark2) does not. The active-theme flag must stay false so the
        // "Active Theme" card doesn't imply quark2 is outdated.
        $gpm = $this->makeGpm(['plugins' => [], 'themes' => ['delivernext' => (object) []]], false);

        $counts = DashboardController::extractUpdateCounts($gpm, 'quark2');

        $this->assertSame(1, $counts['themes']);
        $this->assertFalse($counts['active_theme']);
    }

    #[Test]
    public function flags_active_theme_when_it_is_the_one_updatable(): void
    {
        $gpm = $this->makeGpm(['plugins' => [], 'themes' => ['quark2' => (object) []]], false);

        $counts = DashboardController::extractUpdateCounts($gpm, 'quark2');

        $this->assertTrue($counts['active_theme']);
    }

    #[Test]
    public function active_theme_flag_is_false_without_a_slug(): void
    {
        $gpm = $this->makeGpm(['plugins' => [], 'themes' => ['quark2' => (object) []]], false);

        $counts = DashboardController::extractUpdateCounts($gpm);

        $this->assertFalse($counts['active_theme']);
    }
}
