<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * Decides which config scopes the generic /config and /blueprints/config
 * endpoints accept.
 *
 * Core scopes (system, site, media, security, scheduler, backups) are handled
 * by explicit arms in ConfigController / BlueprintController. Beyond those,
 * site authors can drop a top-level config in via the cookbook "add a custom
 * yaml file" recipe — a `user/blueprints/config/<scope>.yaml` paired with a
 * `user/config/<scope>.yaml`. Admin-classic surfaced those as config tabs
 * automatically; admin2's API used to reject them because every downstream
 * handler hardcoded the 6-scope whitelist.
 *
 * {@see isCustom()} is the single gate those handlers now share. It deliberately
 * keys off a *user/environment-authored* blueprint, NOT the merged
 * `blueprints://config` stream: core ships system blueprints there too (e.g.
 * `streams.yaml`), and those must never become writable through the generic
 * config permission. Requiring the blueprint to live under user:// or
 * environment:// limits custom scopes to ones the site itself defined.
 */
final class ConfigScopes
{
    /**
     * Config scopes the API handles with explicit, individually-guarded arms.
     * Custom scopes can never collide with these — the explicit arms win first.
     */
    public const CORE = ['system', 'site', 'media', 'security', 'scheduler', 'backups'];

    /**
     * True when $scope is a site-authored top-level config (the cookbook custom
     * yaml recipe).
     *
     * A valid custom scope is a flat slug (no slashes or dots — this also blocks
     * path traversal through the `/config/{scope:.+}` route), is not one of the
     * explicitly-handled CORE scopes, and has its config blueprint under the
     * user:// or environment:// blueprints stream.
     */
    public static function isCustom(Grav $grav, ?string $scope): bool
    {
        if ($scope === null || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $scope)) {
            return false;
        }

        if (in_array($scope, self::CORE, true)) {
            return false;
        }

        $locator = $grav['locator'];
        foreach (['user://blueprints/config/', 'environment://blueprints/config/'] as $base) {
            if ($locator->findResource($base . $scope . '.yaml', true)) {
                return true;
            }
        }

        return false;
    }
}
