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
 * `user/config/<scope>.yaml`. Admin-classic showed those as config tabs
 * automatically; admin2's API used to reject them because every downstream
 * handler hardcoded the 6-scope whitelist.
 *
 * {@see isCustom()} is the single gate those handlers now share. It accepts any
 * scope whose config blueprint is contributed from outside core's system://
 * tree — a site-authored `user://blueprints/config/<scope>.yaml` (the cookbook
 * recipe), an environment override, or a third-party plugin/theme that ships
 * `blueprints/config/<scope>.yaml` (admin-classic showed those as config tabs
 * automatically). Core ships its own system blueprints in the same merged
 * `blueprints://config` stream (e.g. `streams.yaml`), and those must never
 * become writable through the generic config permission — so any scope core
 * ships under system:// is rejected first, which also blocks a plugin from
 * shadowing a core scope name to unlock it (plugin blueprint paths rank above
 * system:// in the merged stream).
 */
final class ConfigScopes
{
    /**
     * Config scopes the API handles with explicit, individually-guarded arms.
     * Custom scopes can never collide with these — the explicit arms win first.
     */
    public const CORE = ['system', 'site', 'media', 'security', 'scheduler', 'backups'];

    /**
     * True when $scope is a site- or extension-authored top-level config: the
     * cookbook custom-yaml recipe (`user://blueprints/config/<scope>.yaml`), an
     * environment override, or a third-party plugin/theme that ships
     * `blueprints/config/<scope>.yaml`.
     *
     * A valid custom scope is a flat slug (no slashes or dots — this also blocks
     * path traversal through the `/config/{scope:.+}` route) and is not one of
     * the explicitly-handled CORE scopes. Beyond that it must have at least one
     * blueprint provider OUTSIDE core's system:// tree and NONE inside it: the
     * first admits genuine custom tabs, the second keeps core-shipped system
     * blueprints (e.g. `streams.yaml`) locked and stops a plugin shadowing a
     * core scope name to make it writable.
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

        // Core ships a blueprint for this scope? Then it's never a generic
        // custom scope — this keeps core system blueprints (e.g. `streams`)
        // locked AND stops a plugin from shadowing a core scope name to make it
        // writable (plugin blueprint paths sit above system:// in the merged
        // stream, so without this guard a shadowing file would win the lookup).
        if ($locator->findResource('system://blueprints/config/' . $scope . '.yaml', true)) {
            return false;
        }

        // Otherwise it's custom iff a blueprint for it exists anywhere in the
        // merged stream: the cookbook recipe (user://blueprints/config), an
        // environment override, or a plugin/theme that ships
        // blueprints/config/<scope>.yaml. findResource() is existence-checked
        // and returns false when nothing provides it.
        return $locator->findResource('blueprints://config/' . $scope . '.yaml', true) !== false;
    }
}
