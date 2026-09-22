<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Popularity;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\PermissionResolver;

/**
 * Records page views into PopularityStore. Mirrors the behaviour of
 * admin-classic's tracker (bot/DNT respect, configurable ignore globs)
 * but writes to a SQLite database instead of four JSON files.
 */
class PopularityTracker
{
    /**
     * Command-line and library HTTP clients that are never a person reading a
     * page. Matched case-insensitively anywhere in the User-Agent header.
     *
     * Core's Browser::isHuman() only rejects parsed browser names containing
     * `bot` or `crawl`, and the user-agent parser has no rule for these, so it
     * falls through to a generic name/version pattern and reports `curl` as
     * the browser. A security scanner hammering the site therefore lands in
     * Page Statistics as real traffic.
     *
     * Deliberately not listed: headless Chrome, Lighthouse and uptime
     * monitors. Those are ambiguous enough to be somebody's legitimate
     * traffic, which is what `exclude_agents` is for.
     */
    private const NON_BROWSER_AGENTS = [
        'curl/',
        'wget/',
        'go-http-client/',
        'python-requests/',
        'python-urllib/',
        'libwww-perl/',
        'okhttp/',
        'apache-httpclient/',
        'guzzlehttp/',
        'node-fetch/',
        'axios/',
        'postmanruntime/',
        'insomnia/',
        'httpie/',
        'restsharp/',
        'java/',
        'php/',
    ];

    /**
     * Marker cookie that keeps a browser's own page views out of the stats.
     *
     * Admin2 signs in with a JWT held by the SPA, and the API deliberately
     * leaves the shared front-end session untouched on login, so a front-end
     * page view from an admin2-only admin arrives as a guest. The API sets this
     * cookie on the admin's authenticated calls and clears it on logout, which
     * is how the tracker recognises that browser (getgrav/grav-plugin-api#45).
     *
     * It is a hint, not a credential: it grants nothing and only opts the
     * browser out of counting, the same as Do Not Track, so it is not signed.
     */
    public const EXCLUDE_COOKIE = 'grav-popularity-exclude';

    private Config $config;
    private PopularityStore $store;

    public function __construct(?PopularityStore $store = null)
    {
        $this->config = Grav::instance()['config'];
        $this->store = $store ?? new PopularityStore();
    }

    public function trackHit(): void
    {
        if (!$this->config->get('plugins.api.popularity.enabled', true)) {
            return;
        }

        $grav = Grav::instance();

        if (!$grav['browser']->isHuman()) {
            return;
        }
        if (!$grav['browser']->isTrackable()) {
            return;
        }

        // Skip command-line and library HTTP clients (curl, wget, scanners,
        // monitoring tools). These parse as browsers rather than bots, so
        // isHuman() above lets them through. On by default; `exclude_agents`
        // is an additive list for anything site-specific.
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $excludeAgents = (array) $this->config->get('plugins.api.popularity.exclude_agents', []);
        if ($this->config->get('plugins.api.popularity.exclude_non_browsers', true)) {
            $excludeAgents = array_merge(self::NON_BROWSER_AGENTS, $excludeAgents);
        }
        if ($excludeAgents !== [] && self::agentMatches($agent, $excludeAgents)) {
            return;
        }

        // Skip views from logged-in admins so an author's own testing and
        // demo visits don't skew the real-visitor numbers. On by default.
        if ($this->config->get('plugins.api.popularity.exclude_admin', true)
            && (!empty($_COOKIE[self::EXCLUDE_COOKIE]) || self::isAdminUser($grav['user'] ?? null))) {
            return;
        }

        // Skip views from explicitly excluded visitor IPs / CIDR ranges.
        $ip = (string) $grav['uri']->ip();
        $excludeIps = (array) $this->config->get('plugins.api.popularity.exclude_ips', []);
        if ($excludeIps !== [] && self::ipMatches($ip, $excludeIps)) {
            return;
        }

        /** @var \Grav\Common\Page\Interfaces\PageInterface|null $page */
        $page = $grav['page'] ?? null;
        if ($page === null || $page->route() === null) {
            return;
        }
        if ($page->template() === 'error') {
            return;
        }

        // A page carrying `routes.default: ''` has a legitimately empty public
        // route, which used to fail the guard above and go uncounted. It is a
        // real, reachable page, so it gets tracked like any other; the store
        // keys records by route, and an empty string is no use as a key, so
        // fall back to the structural route which is always present
        // (getgrav/grav-plugin-api#34).
        $route = $page->route();
        if ($route === '') {
            $route = (string) $page->rawRoute();
        }
        $url = (string) str_replace($grav['base_url_relative'], '', $page->url());

        foreach ((array) $this->config->get('plugins.api.popularity.ignore', []) as $ignore) {
            if (fnmatch((string) $ignore, $url)) {
                return;
            }
        }

        try {
            // Pruning happens inside recordHit() under the same lock — every
            // write trims to the configured retention window, so the file
            // can never grow beyond bounded size between hits.
            $this->store->recordHit(
                $route,
                null,
                (int) $this->config->get('plugins.api.popularity.history.daily', 30),
                (int) $this->config->get('plugins.api.popularity.history.monthly', 12),
            );
        } catch (\Throwable) {
            // Tracking must never break the page response — swallow.
        }
    }

    /**
     * Match a User-Agent header against a list of exclusion patterns. A
     * pattern matches if it appears anywhere in the header, ignoring case
     * (e.g. `curl/` matches `curl/8.7.1`). Substring rather than glob
     * matching, because a user-agent is a free-form string, not a path.
     *
     * @param array<int, string> $patterns
     */
    public static function agentMatches(string $agent, array $patterns): bool
    {
        if ($agent === '') {
            return false;
        }

        $agent = strtolower($agent);
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern !== '' && str_contains($agent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a visitor IP against a list of exclusion patterns. Each pattern is
     * either an exact IP (e.g. `203.0.113.7`, `2001:db8::1`) or a CIDR range
     * (e.g. `203.0.113.0/24`, `2001:db8::/32`). IPv4 and IPv6 are both
     * supported; a pattern of the wrong family for the visitor is skipped.
     */
    public static function ipMatches(string $ip, array $patterns): bool
    {
        $ipPacked = @inet_pton($ip);
        if ($ipPacked === false) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            if (!str_contains($pattern, '/')) {
                // Exact match — normalise both sides via inet_pton so e.g.
                // `::1` and `0:0:0:0:0:0:0:1` compare equal.
                $patternPacked = @inet_pton($pattern);
                if ($patternPacked !== false && $patternPacked === $ipPacked) {
                    return true;
                }
                continue;
            }

            [$subnet, $bits] = explode('/', $pattern, 2);
            $subnetPacked = @inet_pton(trim($subnet));
            if ($subnetPacked === false || !ctype_digit(trim($bits))) {
                continue;
            }
            // Different address families (v4 vs v6) can never match.
            if (strlen($subnetPacked) !== strlen($ipPacked)) {
                continue;
            }

            $bits = (int) $bits;
            $maxBits = strlen($ipPacked) * 8;
            if ($bits < 0 || $bits > $maxBits) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }

            $bytes = intdiv($bits, 8);
            $remainder = $bits % 8;

            if ($bytes > 0 && substr($ipPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
                continue;
            }
            if ($remainder !== 0) {
                $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
                if ((ord($ipPacked[$bytes]) & $mask) !== (ord($subnetPacked[$bytes]) & $mask)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Whether a user counts as an admin for `exclude_admin`: an Admin2 account
     * (`api.access`, or `api.super` granted on its own) or a classic admin
     * (`admin.login`). All three go through the API's PermissionResolver, the
     * same lookup the API gates on, so a grant from one of the user's groups
     * counts. A front-end user must also be fully signed in (past 2FA).
     */
    public static function isAdminUser(?UserInterface $user): bool
    {
        if ($user === null || !$user->get('authenticated') || !$user->get('authorized', true)) {
            return false;
        }
        if ($user->get('state', 'enabled') !== 'enabled') {
            return false;
        }

        $resolver = new PermissionResolver();

        return $resolver->resolve($user, 'api.access') === true
            || $resolver->resolveExact($user, 'api.super') === true
            || $resolver->resolve($user, 'admin.login') === true;
    }

    /**
     * Set or clear the EXCLUDE_COOKIE marker for this browser. Scoped to the
     * site root so it reaches every front-end page, HttpOnly since nothing
     * client-side needs it, and it lives as long as an Admin2 sign-in can
     * (the refresh-token lifetime); any later authenticated call sets it again.
     */
    public static function sendExcludeCookie(bool $exclude): void
    {
        if (headers_sent()) {
            return;
        }

        $grav = Grav::instance();
        $lifetime = (int) $grav['config']->get('plugins.api.auth.jwt_refresh_expiry', 604800);

        setcookie(self::EXCLUDE_COOKIE, $exclude ? '1' : '', [
            'expires' => $exclude ? time() + $lifetime : 1,
            'path' => $grav['uri']->rootUrl(false) ?: '/',
            'secure' => $grav['uri']->scheme(true) === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
