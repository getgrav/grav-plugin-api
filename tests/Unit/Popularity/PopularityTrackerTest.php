<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Popularity;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Popularity\PopularityTracker;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PopularityTracker's exclusion matchers: ipMatches() backs the Page
 * Statistics "Excluded IP Addresses" setting, agentMatches() backs the
 * non-browser client list. Both are pure static helpers so they can be tested
 * without a Grav instance.
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

    /**
     * @return array<string, array{0: string, 1: array<int, string>, 2: bool}>
     */
    public static function agentCases(): array
    {
        return [
            'curl hit'               => ['curl/8.7.1', ['curl/'], true],
            'curl case-insensitive'  => ['cURL/8.7.1', ['curl/'], true],
            'wget hit'               => ['Wget/1.21.4', ['wget/'], true],
            'go client hit'          => ['Go-http-client/1.1', ['go-http-client/'], true],
            'python requests hit'    => ['python-requests/2.31.0', ['python-requests/'], true],
            'chrome not matched'     => ['Mozilla/5.0 (Macintosh) Chrome/120.0 Safari/537.36', ['curl/', 'wget/'], false],
            'matches mid-header'     => ['Mozilla/5.0 (compatible; UptimeRobot/2.0)', ['uptimerobot'], true],
            'blank agent never hits' => ['', ['curl/'], false],
            'blank pattern ignored'  => ['curl/8.7.1', ['  '], false],
            'empty pattern list'     => ['curl/8.7.1', [], false],
            'second pattern matches' => ['curl/8.7.1', ['wget/', 'curl/'], true],
        ];
    }

    /**
     * @param array<int, string> $patterns
     */
    #[Test]
    #[DataProvider('agentCases')]
    public function it_matches_user_agents_against_exclusion_patterns(string $agent, array $patterns, bool $expected): void
    {
        $this->assertSame($expected, PopularityTracker::agentMatches($agent, $patterns));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function adminCases(): array
    {
        $signedIn = ['authenticated' => true, 'authorized' => true];

        return [
            'admin2 api.access'          => [$signedIn + ['access' => ['api' => ['access' => true]]], true],
            'admin2 api.super alone'     => [$signedIn + ['access' => ['api' => ['super' => true]]], true],
            'blanket api grant'          => [$signedIn + ['access' => ['api' => true]], true],
            'classic admin.login'        => [$signedIn + ['access' => ['admin' => ['login' => true]]], true],
            'admin2 via group'           => [$signedIn + ['groups' => ['staff']], true],
            'site member only'           => [$signedIn + ['access' => ['site' => ['login' => true]]], false],
            'api.access revoked'         => [$signedIn + ['access' => ['api' => ['access' => false]]], false],
            'not signed in'              => [['access' => ['api' => ['access' => true]]], false],
            'mid 2FA'                    => [['authenticated' => true, 'authorized' => false, 'access' => ['api' => ['access' => true]]], false],
            'disabled account'           => [$signedIn + ['state' => 'disabled', 'access' => ['api' => ['access' => true]]], false],
        ];
    }

    /**
     * An Admin2-only account holds api.* permissions and never admin.login,
     * so exclude_admin has to recognise both (getgrav/grav-plugin-api#45).
     *
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('adminCases')]
    public function it_recognises_admin2_and_classic_admins(array $data, bool $expected): void
    {
        TestHelper::createMockGrav([
            'config' => new Config(['groups' => ['staff' => ['access' => ['api' => ['access' => true]]]]]),
        ]);

        $this->assertSame($expected, PopularityTracker::isAdminUser(TestHelper::createMockUser('someone', $data)));
    }

    #[Test]
    public function a_guest_is_never_an_admin(): void
    {
        $this->assertFalse(PopularityTracker::isAdminUser(null));
    }
}
