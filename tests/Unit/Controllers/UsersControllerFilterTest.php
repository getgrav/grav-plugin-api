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

    /**
     * @param array<int, mixed> $contributed
     * @return array<int, array<string, mixed>>
     */
    private function assembleTabs(UsersController $c, array $contributed, UserInterface $user): array
    {
        $m = new ReflectionMethod($c, 'assembleFilterTabs');
        $m->setAccessible(true);
        return $m->invoke($c, $contributed, $user);
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
        $this->assertSame(['access' => 'admin.login', 'group' => 'editors', 'filter' => ''], $m->invoke($c, $withAccess));

        // `permission` is accepted as an alias for `access`.
        $withAlias = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/users',
            queryParams: ['permission' => 'api.super'],
        );
        $this->assertSame(['access' => 'api.super', 'group' => '', 'filter' => ''], $m->invoke($c, $withAlias));
    }

    #[Test]
    public function assemble_tabs_always_leads_with_the_builtin_all_tab(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $tabs = $this->assembleTabs($c, [], $user);

        $this->assertCount(1, $tabs);
        $this->assertSame('all', $tabs[0]['id']);
        $this->assertSame('api', $tabs[0]['plugin']);
    }

    #[Test]
    public function assemble_tabs_orders_plugin_tabs_by_descending_priority_after_all(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $tabs = $this->assembleTabs($c, [
            ['id' => 'low', 'label' => 'Low', 'priority' => 1],
            ['id' => 'high', 'label' => 'High', 'priority' => 50],
            ['id' => 'mid', 'label' => 'Mid', 'priority' => 10],
        ], $user);

        $this->assertSame(['all', 'high', 'mid', 'low'], array_column($tabs, 'id'));
    }

    #[Test]
    public function assemble_tabs_drops_unauthorized_tabs_and_strips_the_authorize_field(): void
    {
        $c = $this->controller();
        // Has api.users.read but not api.super.
        $user = TestHelper::createMockUser('editor', [
            'access' => ['api' => ['users' => ['read' => true]]],
        ]);

        $tabs = $this->assembleTabs($c, [
            ['id' => 'allowed', 'label' => 'Allowed', 'authorize' => 'api.users.read'],
            ['id' => 'denied', 'label' => 'Denied', 'authorize' => 'api.super'],
        ], $user);

        $this->assertSame(['all', 'allowed'], array_column($tabs, 'id'));
        // The server-side annotation must never reach the client.
        $this->assertArrayNotHasKey('authorize', $tabs[1]);
    }

    #[Test]
    public function assemble_tabs_honours_any_of_authorize_arrays(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('editor', [
            'access' => ['api' => ['users' => ['read' => true]]],
        ]);

        $tabs = $this->assembleTabs($c, [
            ['id' => 'either', 'label' => 'Either', 'authorize' => ['api.super', 'api.users.read']],
        ], $user);

        $this->assertSame(['all', 'either'], array_column($tabs, 'id'));
    }

    #[Test]
    public function assemble_tabs_reserves_the_all_id_and_drops_malformed_entries(): void
    {
        $c = $this->controller();
        $user = TestHelper::createMockUser('anyone', []);

        $tabs = $this->assembleTabs($c, [
            ['id' => 'all', 'label' => 'Hijacked'],   // reserved — dropped
            ['id' => '', 'label' => 'Empty id'],       // malformed — dropped
            ['label' => 'No id'],                      // malformed — dropped
            'not-an-array',                            // malformed — dropped
            ['id' => 'real', 'label' => 'Real'],
        ], $user);

        $this->assertSame(['all', 'real'], array_column($tabs, 'id'));
        // The built-in "all" tab keeps its own label, not the plugin's.
        $this->assertSame('All Users', $tabs[0]['label']);
    }

    #[Test]
    public function get_list_filters_reads_and_trims_the_tab_filter_id(): void
    {
        $c = $this->controller();
        $m = new ReflectionMethod($c, 'getListFilters');
        $m->setAccessible(true);

        $request = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/users',
            queryParams: ['filter' => '  active  '],
        );
        $this->assertSame(
            ['access' => '', 'group' => '', 'filter' => 'active'],
            $m->invoke($c, $request),
        );
    }
}
