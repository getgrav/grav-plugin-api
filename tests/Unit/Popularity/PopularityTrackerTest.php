<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Popularity;

use Grav\Plugin\Api\Popularity\PopularityTracker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PopularityTracker::ipMatches(), the visitor-IP exclusion matcher
 * backing the Page Statistics "Excluded IP Addresses" setting. The matcher is
 * a pure static helper so it can be tested without a Grav instance.
 */
#[CoversClass(PopularityTracker::class)]
class PopularityTrackerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<int, string>, 2: bool}>
     */
    public static function ipCases(): array
    {
        return [
            'exact v4 hit'            => ['203.0.113.7', ['203.0.113.7'], true],
            'exact v4 miss'          => ['203.0.113.8', ['203.0.113.7'], false],
            'v4 /24 hit'             => ['203.0.113.55', ['203.0.113.0/24'], true],
            'v4 /24 miss'            => ['203.0.114.55', ['203.0.113.0/24'], false],
            'v4 /8 hit'              => ['10.1.2.3', ['10.0.0.0/8'], true],
            'v4 /8 miss'             => ['11.1.2.3', ['10.0.0.0/8'], false],
            'v4 /25 hit (remainder)' => ['192.168.1.130', ['192.168.1.128/25'], true],
            'v4 /25 miss (remainder)' => ['192.168.1.100', ['192.168.1.128/25'], false],
            'v6 /32 hit'             => ['2001:db8::1', ['2001:db8::/32'], true],
            'v6 /32 miss'            => ['2001:db9::1', ['2001:db8::/32'], false],
            'v6 exact normalised'    => ['::1', ['0:0:0:0:0:0:0:1'], true],
            'family mismatch cidr'   => ['203.0.113.7', ['2001:db8::/32'], false],
            'family mismatch exact'  => ['2001:db8::1', ['203.0.113.7'], false],
            'zero-bit subnet matches all' => ['1.2.3.4', ['0.0.0.0/0'], true],
            'blank pattern ignored'  => ['1.2.3.4', ['  '], false],
            'garbage pattern ignored' => ['1.2.3.4', ['garbage'], false],
            'out-of-range bits ignored' => ['1.2.3.4', ['1.2.3.0/33'], false],
            'invalid visitor ip'     => ['not-an-ip', ['1.2.3.4'], false],
            'empty pattern list'     => ['1.2.3.4', [], false],
            'second pattern matches' => ['1.2.3.4', ['9.9.9.9', '1.2.3.0/24'], true],
        ];
    }

    /**
     * @param array<int, string> $patterns
     */
    #[Test]
    #[DataProvider('ipCases')]
    public function it_matches_ips_against_exact_and_cidr_patterns(string $ip, array $patterns, bool $expected): void
    {
        $this->assertSame($expected, PopularityTracker::ipMatches($ip, $patterns));
    }
}
