<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * grav-plugin-api#49: deleting your own account was refused, but disabling it
 * (or stripping your own super-admin access) went through and locked the
 * account out of the admin, with only a hand edit of the account file to undo it.
 */
#[CoversClass(UsersController::class)]
class UsersControllerSelfLockoutTest extends TestCase
{
    private string $tempDir;

    private const SUPER_ACCESS = [
        'admin' => ['login' => true, 'super' => true],
        'api'   => ['access' => true, 'super' => true],
        'site'  => ['login' => true],
    ];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_users_self_lockout_' . uniqid();
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
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

    private function controller(UserInterface ...$users): UsersController
    {
        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => ['twofa_enabled' => false]],
            'groups' => [
                'admins' => ['access' => self::SUPER_ACCESS],
            ],
        ]);

        $base = $this->tempDir;
        $locator = new class ($base) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
            {
                return str_starts_with($uri, 'cache://') ? $this->base . '/cache' : $this->base;
            }
        };

        $map = [];
        foreach ($users as $user) {
            $map[$user->username] = $user;
        }

        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => $locator,
            'accounts'    => TestHelper::createMockAccounts($map),
            'permissions' => new Permissions(),
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function patch(UserInterface $caller, string $target, array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'PATCH',
            path: '/api/v1/users/' . $target,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user'     => $caller,
                'json_body'    => $body,
                'route_params' => ['username' => $target],
            ],
        );
    }

    private function superUser(string $name = 'alice'): UserInterface
    {
        return TestHelper::createMockUser($name, ['state' => 'enabled', 'access' => self::SUPER_ACCESS]);
    }

    /** @param array<string, mixed> $body */
    private function assertRefused(UsersController $c, UserInterface $caller, string $target, array $body, string $message): void
    {
        try {
            $c->update($this->patch($caller, $target, $body));
            $this->fail('The self-lockout edit must be refused.');
        } catch (ForbiddenException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    #[Test]
    public function super_admin_cannot_disable_own_account(): void
    {
        $alice = $this->superUser();
        $c = $this->controller($alice);

        $this->assertRefused($c, $alice, 'alice', ['state' => 'disabled'], 'You cannot disable your own account.');
        $this->assertSame('enabled', $alice->get('state'));
        $this->assertNull($alice->get('api_tokens_valid_after'), 'A refused edit must not revoke the caller\'s tokens.');
    }

    #[Test]
    public function any_non_enabled_state_counts_as_disabling(): void
    {
        // Core treats anything but 'enabled' as a disabled account.
        $alice = $this->superUser();
        $c = $this->controller($alice);

        $this->assertRefused($c, $alice, 'alice', ['state' => 'locked'], 'You cannot disable your own account.');
        $this->assertSame('enabled', $alice->get('state'));
    }

    #[Test]
    public function user_manager_cannot_disable_own_account(): void
    {
        $manager = TestHelper::createMockUser('manager', [
            'state'  => 'enabled',
            'access' => ['api' => ['access' => true, 'users' => ['write' => true]]],
        ]);
        $c = $this->controller($manager);

        $this->assertRefused($c, $manager, 'manager', ['state' => 'disabled'], 'You cannot disable your own account.');
        $this->assertSame('enabled', $manager->get('state'));
    }

    #[Test]
    public function resending_own_enabled_state_still_saves(): void
    {
        // Admin2's edit form sends the unchanged state and access back on
        // every save; that must keep working on your own account.
        $alice = $this->superUser();
        $c = $this->controller($alice);

        $c->update($this->patch($alice, 'alice', [
            'fullname' => 'Alice Admin',
            'state'    => 'enabled',
            'access'   => self::SUPER_ACCESS,
        ]));

        $this->assertSame('Alice Admin', $alice->get('fullname'));
        $this->assertSame('enabled', $alice->get('state'));
    }

    #[Test]
    public function super_admin_can_still_disable_another_account(): void
    {
        $alice = $this->superUser();
        $bob = $this->superUser('bob');
        $c = $this->controller($alice, $bob);

        $c->update($this->patch($alice, 'bob', ['state' => 'disabled']));

        $this->assertSame('disabled', $bob->get('state'));
    }

    #[Test]
    public function super_admin_cannot_remove_own_super_via_access(): void
    {
        $alice = $this->superUser();
        $c = $this->controller($alice);

        $this->assertRefused(
            $c,
            $alice,
            'alice',
            ['access' => ['api' => ['access' => true], 'site' => ['login' => true]]],
            'You cannot remove super-admin access from your own account.',
        );
    }

    #[Test]
    public function super_admin_cannot_leave_the_group_that_makes_them_super(): void
    {
        $alice = TestHelper::createMockUser('alice', [
            'state'  => 'enabled',
            'access' => ['site' => ['login' => true]],
            'groups' => ['admins'],
        ]);
        $c = $this->controller($alice);

        $this->assertRefused($c, $alice, 'alice', ['groups' => []], 'You cannot remove super-admin access from your own account.');
    }

    #[Test]
    public function super_admin_can_move_own_super_from_group_to_account(): void
    {
        // Still super afterwards, so nothing is locked out.
        $alice = TestHelper::createMockUser('alice', [
            'state'  => 'enabled',
            'access' => ['site' => ['login' => true]],
            'groups' => ['admins'],
        ]);
        $c = $this->controller($alice);

        $c->update($this->patch($alice, 'alice', ['groups' => [], 'access' => self::SUPER_ACCESS]));

        $this->assertSame([], $alice->get('groups'));
    }

    #[Test]
    public function super_admin_can_still_demote_another_super_admin(): void
    {
        $alice = $this->superUser();
        $bob = $this->superUser('bob');
        $c = $this->controller($alice, $bob);

        $newAccess = ['api' => ['access' => true], 'site' => ['login' => true]];
        $c->update($this->patch($alice, 'bob', ['access' => $newAccess]));

        $this->assertSame($newAccess, $bob->get('access'));
    }
}
