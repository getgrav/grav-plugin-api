<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\InvitationsController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A non-super user manager (api.users.write) may only grant permissions its
 * own account holds. Before this, the only limit was "not super", so a manager
 * could hand out api.gpm.write or api.config.write, super in all but name.
 */
#[CoversClass(AbstractApiController::class)]
#[CoversClass(UsersController::class)]
#[CoversClass(InvitationsController::class)]
class UsersControllerGrantOwnPermissionsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_grant_own_test_' . uniqid();
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        Grav::resetInstance();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<string, mixed> $groups */
    private function config(array $groups = []): Config
    {
        return new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => ['twofa_enabled' => false]],
            'groups' => $groups,
        ]);
    }

    /**
     * @param array<string, UserInterface> $users
     * @param array<string, mixed> $groups
     */
    private function usersController(array $users, array $groups = []): UsersController
    {
        $config = $this->config($groups);
        $base = $this->tempDir;
        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => new class ($base) {
                public function __construct(private string $base) {}
                public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
                {
                    return str_starts_with($uri, 'cache://') ? $this->base . '/cache' : $this->base;
                }
            },
            'accounts'    => TestHelper::createMockAccounts($users),
            'permissions' => new Permissions(),
        ]);

        return new UsersController(Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function patch(UserInterface $caller, string $target, array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'PATCH',
            path: '/api/v1/users/' . $target,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: ['api_user' => $caller, 'json_body' => $body, 'route_params' => ['username' => $target]],
        );
    }

    private function manager(array $extra = []): UserInterface
    {
        return TestHelper::createMockUser('manager', [
            'access' => array_replace_recursive([
                'api' => ['access' => true, 'users' => ['read' => true, 'write' => true], 'pages' => ['read' => true]],
                'site' => ['login' => true],
            ], $extra),
        ]);
    }

    private function assertForbidden(callable $fn, string $permission): void
    {
        try {
            $fn();
            $this->fail("Granting '{$permission}' must be refused.");
        } catch (ForbiddenException $e) {
            $this->assertStringContainsString("'{$permission}'", $e->getMessage());
        }
    }

    #[Test]
    public function manager_cannot_grant_a_permission_it_does_not_hold(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', ['access' => ['api' => ['access' => true]]]);
        $original = $target->get('access');
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $this->assertForbidden(
            fn () => $controller->update($this->patch($manager, 'user1', [
                'access' => ['api' => ['access' => true, 'gpm' => ['write' => true]]],
            ])),
            'api.gpm.write'
        );
        $this->assertSame($original, $target->get('access'));
    }

    #[Test]
    public function manager_cannot_grant_itself_more(): void
    {
        $manager = $this->manager();
        $controller = $this->usersController(['manager' => $manager]);
        $access = $manager->get('access');
        $access['api']['config'] = ['write' => true];

        $this->assertForbidden(
            fn () => $controller->update($this->patch($manager, 'manager', ['access' => $access])),
            'api.config.write'
        );
    }

    #[Test]
    public function manager_cannot_grant_a_parent_key_it_only_holds_children_of(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', ['access' => []]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $this->assertForbidden(
            fn () => $controller->update($this->patch($manager, 'user1', ['access' => ['api' => ['pages' => true]]])),
            'api.pages'
        );
    }

    #[Test]
    public function a_compact_action_string_counts_as_a_grant(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', ['access' => []]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $this->assertForbidden(
            fn () => $controller->update($this->patch($manager, 'user1', ['access' => ['admin' => ['flex-objects' => 'crud']]])),
            'admin.flex-objects'
        );
    }

    #[Test]
    public function manager_can_grant_what_it_holds(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', ['access' => []]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $access = ['api' => ['access' => true, 'pages' => ['read' => true]], 'site' => ['login' => true]];
        $controller->update($this->patch($manager, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function a_permission_held_through_a_group_can_be_granted(): void
    {
        $manager = $this->manager();
        $manager->set('groups', ['editors']);
        $target = TestHelper::createMockUser('user1', ['access' => []]);
        $controller = $this->usersController(
            ['manager' => $manager, 'user1' => $target],
            ['editors' => ['access' => ['api' => ['pages' => ['write' => true]]]]]
        );

        $access = ['api' => ['access' => true, 'pages' => ['write' => true]]];
        $controller->update($this->patch($manager, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function grants_the_target_already_has_are_kept_on_save(): void
    {
        // Admin2 sends the whole access map back on every save, so editing an
        // account that holds more than the manager must still work.
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true, 'config' => ['write' => true]]],
        ]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $access = ['api' => ['access' => true, 'config' => ['write' => true], 'pages' => ['read' => true]]];
        $controller->update($this->patch($manager, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function a_blanket_grant_the_target_has_can_be_expanded_into_its_children(): void
    {
        // Editing one child of `api.config: true` in Admin2 rewrites the blanket
        // as explicit children; that is not a new grant.
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true, 'config' => true]],
        ]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $access = ['api' => ['access' => true, 'config' => ['read' => true, 'write' => false]]];
        $controller->update($this->patch($manager, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function manager_can_revoke_a_permission_it_does_not_hold(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true, 'config' => ['write' => true]]],
        ]);
        $controller = $this->usersController(['manager' => $manager, 'user1' => $target]);

        $access = ['api' => ['access' => true, 'config' => ['write' => false]]];
        $controller->update($this->patch($manager, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function a_super_admin_can_grant_anything(): void
    {
        $super = TestHelper::createMockUser('admin', ['access' => ['api' => ['access' => true, 'super' => true]]]);
        $target = TestHelper::createMockUser('user1', ['access' => []]);
        $controller = $this->usersController(['admin' => $super, 'user1' => $target]);

        $access = ['api' => ['access' => true, 'gpm' => ['write' => true], 'config' => ['write' => true]]];
        $controller->update($this->patch($super, 'user1', ['access' => $access]));

        $this->assertSame($access, $target->get('access'));
    }

    #[Test]
    public function create_refuses_a_permission_the_manager_does_not_hold(): void
    {
        $manager = $this->manager();
        $controller = $this->usersController(['manager' => $manager]);
        // A fresh, not-yet-saved account the controller can set fields on.
        $fresh = new class {
            private array $data = [];
            public bool $saved = false;
            public function exists(): bool { return false; }
            public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function save(): void { $this->saved = true; }
        };
        Grav::instance()['accounts'] = new class ($fresh) {
            public function __construct(private object $fresh) {}
            public function load(string $username): object { return $this->fresh; }
        };
        $body = [
            'username' => 'newuser',
            'password' => 'Sup3r-Secret-Passw0rd!',
            'email'    => 'new@example.com',
            'access'   => ['api' => ['access' => true, 'system' => ['write' => true]]],
        ];

        $this->assertForbidden(
            fn () => $controller->create(TestHelper::createMockRequest(
                method: 'POST',
                path: '/api/v1/users',
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body),
                attributes: ['api_user' => $manager, 'json_body' => $body],
            )),
            'api.system.write'
        );
        $this->assertFalse($fresh->saved);
        $this->assertNull($fresh->get('access'));
    }

    #[Test]
    public function an_invitation_cannot_preset_a_permission_the_inviter_does_not_hold(): void
    {
        $manager = $this->manager();
        $config = $this->config();
        TestHelper::createMockGrav([
            'config'      => $config,
            'permissions' => new Permissions(),
            'accounts'    => new class {
                public function find($value, $fields = null) { return null; }
            },
        ]);
        $controller = new InvitationsController(Grav::instance(), $config);
        $body = ['email' => 'invitee@example.com', 'access' => ['api' => ['access' => true, 'gpm' => ['write' => true]]]];

        $this->assertForbidden(
            fn () => $controller->create(TestHelper::createMockRequest(
                method: 'POST',
                path: '/api/v1/invitations',
                headers: ['Content-Type' => 'application/json'],
                body: json_encode($body),
                attributes: ['api_user' => $manager, 'json_body' => $body],
            )),
            'api.gpm.write'
        );
    }
}
