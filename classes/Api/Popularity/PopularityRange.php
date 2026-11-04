<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Popularity;

/**
 * Builds the dashboard's page-view chart for one time frame from the
 * store's daily and monthly maps.
 *
 * Day ranges plot one point per day; `12m` plots one point per calendar
 * month from the monthly totals. Every range also reports its total and the
 * total of the period of the same length just before it, so the dashboard
 * can show the change. `previous` is null when the stored history doesn't
 * reach back to the start of that earlier period, rather than a misleading 0.
 */
final class PopularityRange
{
    public const DEFAULT = '14d';

    /** Range key => [unit, length]. */
    public const RANGES = [
        '7d' => ['day', 7],
        '14d' => ['day', 14],
        '30d' => ['day', 30],
        '90d' => ['day', 90],
        '12m' => ['month', 12],
    ];

    public static function normalize(?string $range): string
    {
        $range = strtolower(trim((string) $range));

        return isset(self::RANGES[$range]) ? $range : self::DEFAULT;
    }

    /**
     * @param array<string,int> $daily   'Y-m-d' => views
     * @param array<string,int> $monthly 'Y-m' => views
     * @return array{range: array{key: string, unit: string, total: int, previous: int|null}, chart: list<array{key: string, date: string, views: int}>}
     */
    public static function build(array $daily, array $monthly, string $range, ?int $now = null): array
    {
        $now ??= time();
        $range = self::normalize($range);
        [$unit, $length] = self::RANGES[$range];

        if ($unit === 'month') {
            $source = $monthly;
            $keyFormat = 'Y-m';
            $labelFormat = 'M Y';
            $at = static fn(int $offset): int => strtotime(date('Y-m-01', $now) . " -{$offset} months");
        } else {
            $source = $daily;
            $keyFormat = 'Y-m-d';
            $labelFormat = 'M j';
            $at = static fn(int $offset): int => strtotime(date('Y-m-d', $now) . " -{$offset} days");
        }

        $chart = [];
        $total = 0;
        for ($i = $length - 1; $i >= 0; $i--) {
            $ts = $at($i);
            $key = date($keyFormat, $ts);
            $views = (int) ($source[$key] ?? 0);
            $total += $views;
            $chart[] = ['key' => $key, 'date' => date($labelFormat, $ts), 'views' => $views];
        }

        $previous = 0;
        for ($i = 2 * $length - 1; $i >= $length; $i--) {
            $previous += (int) ($source[date($keyFormat, $at($i))] ?? 0);
        }

        // Only compare when the history covers the whole earlier period.
        $keys = array_keys($source);
        $earliest = $keys === [] ? null : min($keys);
        $previousStart = date($keyFormat, $at(2 * $length - 1));
        if ($earliest === null || $earliest > $previousStart) {
            $previous = null;
        }

        return [
            'range' => ['key' => $range, 'unit' => $unit, 'total' => $total, 'previous' => $previous],
            'chart' => $chart,
        ];
    }
}
