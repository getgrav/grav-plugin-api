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
}
