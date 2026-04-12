<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

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
     * Lazily flatten $user->get('access') from nested array to dot-notation keys.
     * Cached per user instance within this resolver.
     */
    private function getFlatAccess(UserInterface $user): array
    {
        if ($this->flatAccess === null || $this->flatAccessUser !== $user) {
            $nested = $user->get('access');
            $this->flatAccess = is_array($nested)
                ? Utils::arrayFlattenDotNotation($nested)
                : [];
            $this->flatAccessUser = $user;
        }
        return $this->flatAccess;
    }
}
