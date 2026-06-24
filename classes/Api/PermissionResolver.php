<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;
use Grav\Framework\Acl\Permissions;

/**
 * Hierarchical permission resolver for the API layer.
 *
 * Grav's User::authorize() requires admin context, so the API uses direct
 * access-array lookups. This class adds parent-key inheritance so granting
 * "api.pages" implicitly covers "api.pages.read", matching how Grav's
 * core Access::get() resolves permissions.
 */
class PermissionResolver
{
    /** @var array<string, mixed>|null Lazy-flattened user access map (one per instance). */
    private ?array $flatAccess = null;

    /** @var UserInterface|null The user whose access was flattened — used to invalidate cache. */
    private ?UserInterface $flatAccessUser = null;

    public function __construct(private readonly Permissions $permissions) {}

    /**
     * Resolve a single permission for a user with parent-key inheritance.
     *
     * Walks up the dot-path (api.pages.read → api.pages → api) and returns
     * the first explicitly set value, or null if nothing is set at any level.
     */
    public function resolve(UserInterface $user, string $permission): ?bool
    {
        $flat = $this->getFlatAccess($user);

        $key = $permission;
        while ($key !== '') {
            if (array_key_exists($key, $flat)) {
                $value = $flat[$key];
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 1 || $value === '1' || $value === 'true') {
                    return true;
                }
                if ($value === 0 || $value === '0' || $value === 'false' || $value === null) {
                    return false;
                }
            }
            $pos = strrpos($key, '.');
            $key = $pos !== false ? substr($key, 0, $pos) : '';
        }

        return null;
    }

    /**
     * Build a flat map of all registered api.* permissions with resolved
     * true/false values. Super-admins receive true for everything.
     *
     * @return array<string, bool>
     */
    public function resolvedMap(UserInterface $user, bool $isSuperAdmin): array
    {
        $allInstances = $this->permissions->getInstances();

        $result = [];
        foreach ($allInstances as $name => $action) {
            if (!str_starts_with($name, 'api.')) {
                continue;
            }
            $result[$name] = $isSuperAdmin ? true : (bool) $this->resolve($user, $name);
        }

        return $result;
    }

    /**
     * Lazily build the user's effective access map as dot-notation keys,
     * merging group-inherited access with the user's own access.
     * Cached per user instance within this resolver.
     */
    private function getFlatAccess(UserInterface $user): array
    {
        if ($this->flatAccess === null || $this->flatAccessUser !== $user) {
            $this->flatAccess = $this->buildFlatAccess($user);
            $this->flatAccessUser = $user;
        }
        return $this->flatAccess;
    }

    /**
     * Combine the access maps of every group the user belongs to, then overlay
     * the user's own access on top.
     *
     * This mirrors Grav core's User::authorize(): a permission granted by any
     * group counts (so positive group grants win over a later group's negative),
     * while an explicit value in the user's own access — including an explicit
     * `false` — always overrides whatever the groups resolved to.
     *
     * @return array<string, mixed>
     */
    private function buildFlatAccess(UserInterface $user): array
    {
        $flat = [];

        $config = Grav::instance()['config'] ?? null;
        if ($config !== null) {
            foreach ((array) $user->get('groups', []) as $group) {
                if (!is_string($group)) {
                    continue;
                }
                $groupAccess = $config->get("groups.{$group}.access");
                if (!is_array($groupAccess)) {
                    continue;
                }
                foreach (Utils::arrayFlattenDotNotation($groupAccess) as $key => $value) {
                    // Don't let a later group clobber an earlier positive grant.
                    if (array_key_exists($key, $flat) && Utils::isPositive($flat[$key])) {
                        continue;
                    }
                    $flat[$key] = $value;
                }
            }
        }

        $own = $user->get('access');
        if (is_array($own)) {
            $flat = array_merge($flat, Utils::arrayFlattenDotNotation($own));
        }

        return $flat;
    }
}
