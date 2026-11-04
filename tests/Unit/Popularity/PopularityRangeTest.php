<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Popularity;

use Grav\Plugin\Api\Popularity\PopularityRange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard's Page Views chart can show 7, 14, 30 or 90 days, or 12
 * months, with the total for the time frame and the one before it.
 */
#[CoversClass(PopularityRange::class)]
class PopularityRangeTest extends TestCase
{
    /** 2026-11-03 12:00 local time. */
    private int $now;

    protected function setUp(): void
    {
        $this->now = (int) strtotime('2026-11-03 12:00:00');
    }

    #[Test]
    public function unknown_or_missing_ranges_fall_back_to_14_days(): void
    {
        self::assertSame('14d', PopularityRange::normalize(null));
        self::assertSame('14d', PopularityRange::normalize('5y'));
        self::assertSame('30d', PopularityRange::normalize(' 30D '));
        self::assertSame('12m', PopularityRange::normalize('12m'));
    }

    #[Test]
    public function a_day_range_plots_one_point_per_day_ending_today(): void
    {
        $daily = ['2026-10-28' => 4, '2026-11-01' => 2, '2026-11-03' => 1, '2026-10-27' => 99];

        $result = PopularityRange::build($daily, [], '7d', $this->now);

        self::assertCount(7, $result['chart']);
        self::assertSame(['key' => '2026-10-28', 'date' => 'Oct 28', 'views' => 4], $result['chart'][0]);
        self::assertSame(['key' => '2026-11-03', 'date' => 'Nov 3', 'views' => 1], $result['chart'][6]);
        self::assertSame(7, $result['range']['total']);
        self::assertSame('day', $result['range']['unit']);
    }

    #[Test]
    public function the_previous_period_is_the_same_length_just_before(): void
    {
        $daily = [
            '2026-10-21' => 5,  // first day of the previous 7
            '2026-10-27' => 3,  // last day of the previous 7
            '2026-10-20' => 50, // before both periods
            '2026-11-02' => 6,
        ];

        $result = PopularityRange::build($daily, [], '7d', $this->now);

        self::assertSame(6, $result['range']['total']);
        self::assertSame(8, $result['range']['previous']);
    }

    #[Test]
    public function previous_is_null_when_history_does_not_reach_back_far_enough(): void
    {
        // Only 30 days kept: a 30-day range has nothing to compare with.
        $daily = ['2026-10-05' => 2, '2026-11-03' => 1];

        self::assertNull(PopularityRange::build($daily, [], '30d', $this->now)['range']['previous']);
        self::assertNull(PopularityRange::build([], [], '7d', $this->now)['range']['previous']);
        self::assertSame(0, PopularityRange::build($daily, [], '14d', $this->now)['range']['previous']);
    }

    #[Test]
    public function the_12_month_range_uses_monthly_totals(): void
    {
        $monthly = ['2024-11' => 100, '2024-12' => 10, '2025-10' => 3, '2025-12' => 7, '2026-11' => 2];

        $result = PopularityRange::build([], $monthly, '12m', $this->now);

        self::assertCount(12, $result['chart']);
        self::assertSame(['key' => '2025-12', 'date' => 'Dec 2025', 'views' => 7], $result['chart'][0]);
        self::assertSame(['key' => '2026-11', 'date' => 'Nov 2026', 'views' => 2], $result['chart'][11]);
        self::assertSame(9, $result['range']['total']);
        self::assertSame(13, $result['range']['previous']);
        self::assertSame('month', $result['range']['unit']);
    }

    #[Test]
    public function month_steps_do_not_skip_short_months(): void
    {
        // From the 31st, "-1 month" on the day would land on Oct 1 and skip
        // September; stepping from the 1st of the month avoids that.
        $now = (int) strtotime('2026-10-31 12:00:00');
        $keys = array_column(PopularityRange::build([], [], '12m', $now)['chart'], 'key');

        self::assertSame('2025-11', $keys[0]);
        self::assertContains('2026-09', $keys);
        self::assertSame('2026-10', $keys[11]);
        self::assertCount(12, array_unique($keys));
    }
}
