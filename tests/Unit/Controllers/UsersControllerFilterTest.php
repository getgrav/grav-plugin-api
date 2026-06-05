<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Covers the server-side permission/group filtering added to GET /users so the
 * admin UI can answer "find all admins". Exercises the private matcher helpers
 * directly — the index() entry points just iterate a user list and delegate to
 * these, which carry all of the non-trivial logic (parent-key inheritance,
 * group-inherited access, the super-admin shortcut).
 */
#[CoversClass(UsersController::class)]
class UsersControllerFilterTest extends TestCase
{
    private function controller(array $configData = []): UsersController
    {
        $config = new Config(array_merge_recursive([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ]],
        ], $configData));

        TestHelper::createMockGrav([
            'config'      => $config,
            'permissions' => new Permissions(),
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    private function matchesFilters(UsersController $c, UserInterface $user, array $filters): bool
    {
        $m = new ReflectionMethod($c, 'userMatchesFilters');
        $m->setAccessible(true);
        return $m->invoke($c, $user, $filters);
    }

    private function filters(string $access = '', string $group = ''): array
    {
        return ['access' => $access, 'group' => $group];
    }

    #[Test]
    public function direct_permission_matches(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('editor', [
            'access' => ['admin' => ['login' => true], 'site' => ['login' => true]],
        ]);

        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('admin.login')));
        $this->assertFalse($this->matchesFilters($c, $user, $this->filters('api.users.write')));
    }

    #[Test]
    public function parent_key_inheritance_grants_child_permission(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('pm', [
            'access' => ['api' => ['pages' => true]],
        ]);

        // api.pages covers api.pages.read via walk-up.
        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('api.pages.read')));
    }

    #[Test]
    public function explicit_false_is_not_overridden_by_parent(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('limited', [
            'access' => ['api' => ['pages' => true, 'pages.delete' => false]],
        ]);

        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('api.pages.read')));
        $this->assertFalse($this->matchesFilters($c, $user, $this->filters('api.pages.delete')));
    }

    #[Test]
    public function super_admin_matches_any_permission_filter(): void
    {
        $c = $this->controller();
        $apiSuper = TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);
        $adminSuper = TestHelper::createMockUser('classic', ['access' => ['admin' => ['super' => true]]]);

        foreach (['admin.login', 'api.users.write', 'api.pages.read'] as $perm) {
            $this->assertTrue($this->matchesFilters($c, $apiSuper, $this->filters($perm)), "api.super should match $perm");
            $this->assertTrue($this->matchesFilters($c, $adminSuper, $this->filters($perm)), "admin.super should match $perm");
        }
    }

    #[Test]
    public function group_inherited_access_matches(): void
    {
        $c = $this->controller([
            'groups' => ['editors' => ['access' => ['admin' => ['login' => true]]]],
        ]);
        $user = TestHelper::createMockUser('member', [
            'access' => ['site' => ['login' => true]],
            'groups' => ['editors'],
        ]);

        // Granted only via the group, not directly.
        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('admin.login')));
    }

    #[Test]
    public function own_access_overrides_group_access(): void
    {
        $c = $this->controller([
            'groups' => ['editors' => ['access' => ['admin' => ['login' => true]]]],
        ]);
        $user = TestHelper::createMockUser('revoked', [
            'access' => ['admin' => ['login' => false]],
            'groups' => ['editors'],
        ]);

        $this->assertFalse($this->matchesFilters($c, $user, $this->filters('admin.login')));
    }

    #[Test]
    public function group_filter_checks_membership(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('member', ['groups' => ['editors', 'authors']]);

        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('', 'authors')));
        $this->assertFalse($this->matchesFilters($c, $user, $this->filters('', 'admins')));
    }

    #[Test]
    public function access_and_group_filters_combine(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('member', [
            'access' => ['admin' => ['login' => true]],
            'groups' => ['editors'],
        ]);

        $this->assertTrue($this->matchesFilters($c, $user, $this->filters('admin.login', 'editors')));
        // Right permission, wrong group.
        $this->assertFalse($this->matchesFilters($c, $user, $this->filters('admin.login', 'admins')));
    }

    #[Test]
    public function empty_filters_match_everyone(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $this->assertTrue($this->matchesFilters($c, $user, $this->filters()));
    }

    #[Test]
    public function get_list_filters_reads_access_permission_alias_and_group(): void
    {
        $c = $this->controller();
        $m = new ReflectionMethod($c, 'getListFilters');
        $m->setAccessible(true);

        $withAccess = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/users',
            queryParams: ['access' => ' admin.login ', 'group' => 'editors'],
        );
        $this->assertSame(['access' => 'admin.login', 'group' => 'editors'], $m->invoke($c, $withAccess));

        // `permission` is accepted as an alias for `access`.
        $withAlias = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/users',
            queryParams: ['permission' => 'api.super'],
        );
        $this->assertSame(['access' => 'api.super', 'group' => ''], $m->invoke($c, $withAlias));
    }
}
