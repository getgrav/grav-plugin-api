<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Config\Config;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\PermissionResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers PermissionResolver's effective-access resolution, in particular that
 * group-inherited access is honoured (admin2#57: a user with an empty `access`
 * map but a group membership granting api.* permissions still resolves true).
 */
#[CoversClass(PermissionResolver::class)]
class PermissionResolverTest extends TestCase
{
    private function resolver(array $groups = []): PermissionResolver
    {
        TestHelper::createMockGrav([
            'config'      => new Config(['groups' => $groups]),
            'permissions' => new Permissions(),
        ]);

        return new PermissionResolver(new Permissions());
    }

    #[Test]
    public function group_only_access_resolves_true(): void
    {
        $resolver = $this->resolver([
            'editors' => ['access' => ['api' => [
                'pages'   => true,
                'media'   => true,
                'access'  => true,
                'reports' => true,
            ]]],
        ]);
        $user = TestHelper::createMockUser('member', [
            'access' => [],
            'groups' => ['editors'],
        ]);

        $this->assertTrue($resolver->resolve($user, 'api.pages'));
        $this->assertTrue($resolver->resolve($user, 'api.media'));
        $this->assertTrue($resolver->resolve($user, 'api.reports'));
        // Parent-key inheritance still applies on top of group access.
        $this->assertTrue($resolver->resolve($user, 'api.pages.read'));
    }

    #[Test]
    public function user_access_overrides_group_access(): void
    {
        $resolver = $this->resolver([
            'editors' => ['access' => ['api' => ['pages' => true]]],
        ]);
        $user = TestHelper::createMockUser('revoked', [
            'access' => ['api' => ['pages' => false]],
            'groups' => ['editors'],
        ]);

        $this->assertFalse($resolver->resolve($user, 'api.pages'));
    }

    #[Test]
    public function positive_group_grant_wins_over_later_negative_group(): void
    {
        $resolver = $this->resolver([
            'a' => ['access' => ['api' => ['pages' => true]]],
            'b' => ['access' => ['api' => ['pages' => false]]],
        ]);
        $user = TestHelper::createMockUser('multi', [
            'access' => [],
            'groups' => ['a', 'b'],
        ]);

        $this->assertTrue($resolver->resolve($user, 'api.pages'));
    }

    #[Test]
    public function no_group_and_no_access_resolves_null(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser('nobody', ['access' => [], 'groups' => []]);

        $this->assertNull($resolver->resolve($user, 'api.pages'));
    }

    #[Test]
    public function unknown_group_is_ignored(): void
    {
        $resolver = $this->resolver([
            'editors' => ['access' => ['api' => ['pages' => true]]],
        ]);
        $user = TestHelper::createMockUser('member', [
            'access' => [],
            'groups' => ['does-not-exist'],
        ]);

        $this->assertNull($resolver->resolve($user, 'api.pages'));
    }

    #[Test]
    public function resolve_exact_honors_group_granted_super(): void
    {
        $resolver = $this->resolver([
            'ldap_users' => ['access' => ['api' => ['access' => true, 'super' => true]]],
        ]);
        $user = TestHelper::createMockUser('directory-admin', [
            'access' => ['site' => ['login' => true]],
            'groups' => ['ldap_users'],
        ]);

        $this->assertTrue($resolver->resolveExact($user, 'api.super'));
    }

    #[Test]
    public function resolve_exact_does_not_inherit_from_parent_key(): void
    {
        $resolver = $this->resolver();
        $user = TestHelper::createMockUser('blanket', [
            'access' => ['api' => true],
            'groups' => [],
        ]);

        // resolve() walks api.super → api and says yes; resolveExact() must not,
        // or a blanket `api: true` would quietly promote the account to super.
        $this->assertTrue($resolver->resolve($user, 'api.super'));
        $this->assertNull($resolver->resolveExact($user, 'api.super'));
    }

    #[Test]
    public function resolve_exact_lets_user_access_revoke_group_granted_super(): void
    {
        $resolver = $this->resolver([
            'ldap_users' => ['access' => ['api' => ['super' => true]]],
        ]);
        $user = TestHelper::createMockUser('demoted', [
            'access' => ['api' => ['super' => false]],
            'groups' => ['ldap_users'],
        ]);

        $this->assertFalse($resolver->resolveExact($user, 'api.super'));
    }

    #[Test]
    public function resolve_exact_is_null_when_nothing_grants_it(): void
    {
        $resolver = $this->resolver([
            'editors' => ['access' => ['api' => ['pages' => true]]],
        ]);
        $user = TestHelper::createMockUser('editor', [
            'access' => [],
            'groups' => ['editors'],
        ]);

        $this->assertNull($resolver->resolveExact($user, 'api.super'));
    }
}
