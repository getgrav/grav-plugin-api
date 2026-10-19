<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Demo;

/**
 * What a demo account may change, as plain decisions over strings.
 *
 * No Grav, no request, no user: the router middleware and the controllers ask
 * the same questions here, and the answers can be tested without a site.
 *
 * ## Grants
 *
 * `plugins.api.demo.writable` lists grants. The two original ones still mean
 * what they always did, and each is shorthand for its finer grants:
 *
 *   api.pages.write  = api.pages.create + api.pages.update + api.pages.delete
 *   api.media.write  = api.media.upload + api.media.update + api.media.delete
 *   api.config.write = api.config.site + api.config.plugins + api.config.themes
 *
 * So "edit pages but never delete them" is `[api.pages.create, api.pages.update]`.
 *
 * ## Settings
 *
 * Settings writes are opt-in per group, and some scopes stay locked whatever is
 * granted, because a visitor who could change them could switch demo mode off,
 * widen what the site lets anyone upload or run, or change how the site signs
 * people in or sends mail. `system` is in that list: it carries
 * `twig.safe_functions`, the PHP functions a template may call.
 */
final class DemoPolicy
{
    /** The answer for a write the policy has no rule for: refuse it. */
    public const DENY = 'deny';

    /** A write that changes nothing anybody else sees (a draft preview link). */
    public const OPEN = 'open';

    /** A write the controller decides itself, because the grant is in the body. */
    public const BATCH = 'api.pages.batch';

    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Route families never writable by a demo account, whatever is granted.
     * `/demo` is here because a demo account is usually also super: without it
     * the account could re-capture a vandalised baseline or force a reset.
     * `/config` is not: its scopes are decided one by one in grantFor().
     */
    private const LOCKED_PREFIXES = [
        '/users', '/groups', '/invitations', '/gpm', '/system', '/backups',
        '/webhooks', '/audit', '/demo', '/scheduler', '/cache',
    ];

    /**
     * Permission families the API itself checks. A route opened by
     * `allow_routes` is let past a plugin's own permission checks, never past
     * one of these: listing a core route there cannot unlock what it guards.
     */
    private const CORE_FAMILIES = [
        'api.pages', 'api.media', 'api.config', 'api.users', 'api.system', 'api.translations',
        'api.gpm', 'api.scheduler', 'api.reports', 'api.webhooks', 'api.super', 'api.demo',
        'admin.super',
    ];

    /** Settings scopes no grant opens. See the class comment. */
    private const LOCKED_CONFIG_SCOPES = [
        'system', 'security', 'media', 'scheduler', 'backups', 'accounts', 'streams',
        'plugins/api', 'plugins/admin2', 'plugins/login', 'plugins/email',
    ];

    /** @var array<string, list<string>> */
    private const SHORTHAND = [
        'api.pages.write' => ['api.pages.create', 'api.pages.update', 'api.pages.delete'],
        'api.media.write' => ['api.media.upload', 'api.media.update', 'api.media.delete'],
        'api.config.write' => ['api.config.site', 'api.config.plugins', 'api.config.themes'],
    ];

    private function __construct()
    {
    }

    public static function isMutating(string $method): bool
    {
        return in_array(strtoupper($method), self::MUTATING_METHODS, true);
    }

    /**
     * The configured grants with every shorthand spelled out, so a check only
     * ever has to look for the fine grant.
     *
     * @param array<mixed> $writable
     * @return list<string>
     */
    public static function expand(array $writable): array
    {
        $grants = [];
        foreach ($writable as $grant) {
            $grant = trim((string) $grant);
            if ($grant === '') {
                continue;
            }
            $grants[] = $grant;
            foreach (self::SHORTHAND[$grant] ?? [] as $fine) {
                $grants[] = $fine;
            }
        }

        return array_values(array_unique($grants));
    }

    /**
     * The grants as admin2 reads them: each shorthand present whenever any of
     * its fine grants is, so the editor still opens when only `update` is
     * granted. The server refuses the parts that are not.
     *
     * @param array<mixed> $writable
     * @return list<string>
     */
    public static function coarse(array $writable): array
    {
        $grants = self::expand($writable);
        foreach (self::SHORTHAND as $short => $fine) {
            if (array_intersect($fine, $grants) !== []) {
                $grants[] = $short;
            }
        }
        return array_values(array_unique($grants));
    }

    /**
     * The grant a mutating request needs, DENY when no grant can open it, OPEN
     * when it needs none, or BATCH when the controller has to read the body.
     * Order mirrors the router's, so `/pages/x/media` is a media upload here
     * exactly as it is there.
     */
    public static function grantFor(string $method, string $path): string
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        foreach (self::LOCKED_PREFIXES as $prefix) {
            if (self::under($path, $prefix)) {
                return self::DENY;
            }
        }

        if (self::under($path, '/config')) {
            return self::configGrant($method, $path);
        }

        if ($path === '/pages') {
            return $method === 'POST' ? 'api.pages.create' : self::DENY;
        }
        if ($path === '/pages/batch') {
            return $method === 'POST' ? self::BATCH : self::DENY;
        }
        if ($path === '/pages/reorganize') {
            return $method === 'POST' ? 'api.pages.update' : self::DENY;
        }
        if (self::under($path, '/pages')) {
            return self::pageGrant($method, $path);
        }

        if (self::under($path, '/blueprint-upload')) {
            return match ($method) {
                'POST' => 'api.media.upload',
                'DELETE' => 'api.media.delete',
                default => self::DENY,
            };
        }
        if (self::under($path, '/media')) {
            return self::siteMediaGrant($method, $path);
        }

        return self::DENY;
    }

    /** The grant a page batch operation needs. */
    public static function batchGrant(string $operation): string
    {
        return match ($operation) {
            'publish', 'unpublish' => 'api.pages.update',
            'delete' => 'api.pages.delete',
            'copy' => 'api.pages.create',
            default => self::DENY,
        };
    }

    /**
     * Whether a mutating request is let through.
     *
     * @param list<string> $grants  expand()ed grants
     * @param array<mixed> $allowRoutes  `plugins.api.demo.allow_routes`
     */
    public static function allows(string $method, string $path, array $grants, array $allowRoutes = []): bool
    {
        $grant = self::grantFor($method, $path);

        if ($grant === self::OPEN || $grant === self::BATCH) {
            return true;
        }
        if ($grant !== self::DENY) {
            return in_array($grant, $grants, true);
        }

        return !self::isLocked($path) && self::matchesAllowRoute($method, $path, $allowRoutes);
    }

    /**
     * Whether no `allow_routes` entry may open $path: the locked families and
     * every settings route. Anything else a demo is refused by default (a
     * plugin's own routes, the dashboard layout) can be listed.
     */
    public static function isLocked(string $path): bool
    {
        $path = '/' . trim($path, '/');
        foreach ([...self::LOCKED_PREFIXES, '/config', '/pages', '/media', '/blueprint-upload'] as $prefix) {
            if (self::under($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `allow_routes` entries are `METHOD /path` or just `/path`, with `*`
     * matching anything (slashes included), against the route below the API
     * prefix: `POST /courses/*`, `/quiz/*`.
     *
     * @param array<mixed> $allowRoutes
     */
    public static function matchesAllowRoute(string $method, string $path, array $allowRoutes): bool
    {
        $method = strtoupper($method);
        $path = '/' . trim($path, '/');

        foreach ($allowRoutes as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            $want = null;
            if (preg_match('#^([A-Za-z]+)\s+(\S+)$#', $entry, $m)) {
                $want = strtoupper($m[1]);
                $entry = $m[2];
            }
            if ($want !== null && $want !== $method) {
                continue;
            }
            $pattern = '#^' . str_replace('\*', '.*', preg_quote('/' . trim($entry, '/'), '#')) . '$#';
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> settings scopes no grant opens */
    public static function lockedConfigScopes(): array
    {
        return self::LOCKED_CONFIG_SCOPES;
    }

    /** Whether $permission belongs to a family the API itself checks. */
    public static function isCorePermission(string $permission): bool
    {
        foreach (self::CORE_FAMILIES as $family) {
            if ($permission === $family || str_starts_with($permission, $family . '.')) {
                return true;
            }
        }

        return false;
    }

    /** The group a settings scope belongs to, or null when it is locked. */
    public static function configGroup(string $scope): ?string
    {
        $scope = trim($scope, '/');
        if ($scope === '' || in_array($scope, self::LOCKED_CONFIG_SCOPES, true)) {
            return null;
        }
        if ($scope === 'site') {
            return 'site';
        }
        if (preg_match('#^(plugins|themes)/[^/]+$#', $scope, $m)) {
            return $m[1];
        }

        return null;
    }

    private static function configGrant(string $method, string $path): string
    {
        $rest = substr($path, strlen('/config/'));
        if ($rest === false || $rest === '') {
            return self::DENY;
        }

        if ($method === 'POST' && str_ends_with($rest, '/revert')) {
            $scope = substr($rest, 0, -strlen('/revert'));
        } elseif ($method === 'PATCH') {
            $scope = $rest;
        } else {
            return self::DENY;
        }

        $group = self::configGroup($scope);

        return $group === null ? self::DENY : 'api.config.' . $group;
    }

    private static function pageGrant(string $method, string $path): string
    {
        // Page media first, in the order the router registers it: a file
        // delete is matched before a details delete, so DELETE
        // /pages/x/media/media/meta deletes a file called `meta`.
        if ($method === 'POST' && str_ends_with($path, '/media')) {
            return 'api.media.upload';
        }
        if ($method === 'DELETE' && preg_match('#^/pages/.+/media/[^/]+$#', $path)) {
            return 'api.media.delete';
        }
        if (preg_match('#^/pages/.+/media/[^/]+/meta$#', $path)) {
            return in_array($method, ['PATCH', 'DELETE'], true) ? 'api.media.update' : self::DENY;
        }

        if ($method === 'POST') {
            $action = substr($path, (int) strrpos($path, '/') + 1);

            return match ($action) {
                'preview-token' => self::OPEN,
                'translate', 'copy' => 'api.pages.create',
                'adopt-language', 'sync', 'reorder', 'move' => 'api.pages.update',
                default => self::DENY,
            };
        }

        return match ($method) {
            'PATCH' => 'api.pages.update',
            'DELETE' => 'api.pages.delete',
            default => self::DENY,
        };
    }

    private static function siteMediaGrant(string $method, string $path): string
    {
        if ($method === 'POST') {
            return match ($path) {
                '/media', '/media/folders' => 'api.media.upload',
                '/media/rename', '/media/batch/meta', '/media/order', '/media/folders/rename' => 'api.media.update',
                default => self::DENY,
            };
        }
        if ($path === '/media/meta') {
            return in_array($method, ['PATCH', 'DELETE'], true) ? 'api.media.update' : self::DENY;
        }
        if ($method === 'DELETE' && $path !== '/media') {
            return 'api.media.delete';
        }

        return self::DENY;
    }

    /** `/media` matches `/media` and `/media/x`, never `/mediafoo`. */
    private static function under(string $path, string $prefix): bool
    {
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }
}
