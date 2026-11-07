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
     * @return array<string, array{0: string}>
     */
    public static function nonBrowserAgents(): array
    {
        return [
            'curl'                 => ['curl/8.7.1'],
            'facebookexternalhit'  => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'facebook with mozilla' => ['Mozilla/5.0 (compatible; facebookexternalhit/1.1; +http://www.facebook.com/externalhit_uatext.php)'],
            'meta-externalagent'   => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)'],
            'whatsapp'             => ['WhatsApp/2.23.20.0 A'],
            'slack imgproxy'       => ['Slack-ImgProxy (+https://api.slack.com/robots)'],
            'pinterest'            => ['Pinterest/0.2 (+http://www.pinterest.com/bot.html)'],
            'chatgpt-user'         => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot'],
            'claudebot'            => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)'],
            'perplexity-user'      => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Perplexity-User/1.0; +https://perplexity.ai/perplexity-user)'],
            'bytespider'           => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)'],
            'amazonbot'            => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot) Chrome/119.0.6045.214 Safari/537.36'],
            'uptimerobot'          => ['Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'],
            'pingdom'              => ['Mozilla/5.0 (compatible; PingdomPageSpeed/1.0; +http://www.pingdom.com/)'],
            'better uptime'        => ['Better Uptime Bot Mozilla/5.0 (compatible; Better Uptime Bot; +https://betteruptime.com)'],
            'uptime-kuma'          => ['Uptime-Kuma/1.23.11'],
            'datadog'              => ['Datadog Agent/7.50.0'],
            'guardprobe'           => ['GuardProbe/0.1 (+https://gravguard.com)'],
            'gravguardbot'         => ['GravGuardBot/1.0 (+https://gravguard.com)'],
            'headlesschrome'       => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36'],
            'lighthouse'           => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'],
            'generic crawler'      => ['Mozilla/5.0 (compatible; ExampleCrawler/2.1)'],
            'generic fetcher'      => ['Mozilla/5.0 (compatible; FeedFetcher/2.0)'],
        ];
    }

    #[Test]
    #[DataProvider('nonBrowserAgents')]
    public function it_excludes_built_in_non_human_clients(string $agent): void
    {
        $this->assertTrue(PopularityTracker::isNonBrowserAgent($agent), $agent);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function realBrowserAgents(): array
    {
        return [
            'chrome windows'          => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'safari mac'              => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15'],
            'safari ios'              => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1'],
            'firefox'                 => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'edge'                    => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
            'samsung internet'        => ['Mozilla/5.0 (Linux; Android 13; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36'],
            'facebook in-app ios'     => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21B74 [FBAN/FBIOS;FBAV/440.0.0.30.113;FBBV/560000000;FBDV/iPhone14,5;FBMD/iPhone;FBSN/iOS;FBSV/17.1;FBSS/3;FBID/phone;FBLC/en_US;FBOP/5]'],
            'facebook in-app android' => ['Mozilla/5.0 (Linux; Android 13; Pixel 7 Build/TQ3A.230901.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/119.0.6045.193 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/440.0.0.33.113;]'],
            'instagram in-app'        => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/21B74 Instagram 310.0.0.37.109 (iPhone14,5; iOS 17_1; en_US; en-US; scale=3.00; 1170x2532; 540972892)'],
        ];
    }

    #[Test]
    #[DataProvider('realBrowserAgents')]
    public function it_still_counts_real_browsers_including_in_app_browsers(string $agent): void
    {
        $this->assertFalse(PopularityTracker::isNonBrowserAgent($agent), $agent);
    }

    #[Test]
    public function it_treats_a_blank_agent_as_not_a_listed_client(): void
    {
        $this->assertFalse(PopularityTracker::isNonBrowserAgent(''));
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
