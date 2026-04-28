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
 * Regression tests for GHSA-r945-h4vm-h736.
 *
 * The advisory describes a self-edit IDOR where any user holding `api.access`
 * could PATCH /users/{self} with an `access` payload and self-promote to
 * super-admin. The fix gates `state` and `access` on `api.users.write`; these
 * tests pin that boundary.
 */
#[CoversClass(UsersController::class)]
class UsersControllerUpdatePrivescTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_users_privesc_test_' . uniqid();
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

    private function buildController(UserInterface $targetUser): UsersController
    {
        $tempDir = $this->tempDir;

        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => ['twofa_enabled' => false]],
        ]);

        $locator = new class ($tempDir) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
            {
                if (str_starts_with($uri, 'cache://')) {
                    return $this->base . '/cache';
                }
                return $this->base;
            }
        };

        $accounts = TestHelper::createMockAccounts([$targetUser->username => $targetUser]);

        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => $locator,
            'accounts'    => $accounts,
            // PermissionResolver::resolve() reads only from $user->get('access'),
            // so an empty Permissions() instance is enough to satisfy the type.
            'permissions' => new Permissions(),
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function makeRequest(UserInterface $caller, string $targetUsername, array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'PATCH',
            path: '/api/v1/users/' . $targetUsername,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user'     => $caller,
                'json_body'    => $body,
                'route_params' => ['username' => $targetUsername],
            ],
        );
    }

    // -------------------------------------------------------------------
    // GHSA-r945-h4vm-h736 — privilege escalation via self-edit `access`
    // -------------------------------------------------------------------

    #[Test]
    public function self_edit_with_access_payload_is_rejected_for_low_priv_user(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true], 'site' => ['login' => true]],
            'email'  => 'user1@example.com',
        ]);

        $controller = $this->buildController($user);

        $payload = [
            'access' => [
                'admin' => ['login' => true, 'super' => true],
                'api'   => ['access' => true, 'super' => true],
                'site'  => ['login' => true],
            ],
        ];

        $threw = false;
        try {
            $controller->update($this->makeRequest($user, 'user1', $payload));
        } catch (ForbiddenException $e) {
            $threw = true;
            $this->assertStringContainsString("'access'", $e->getMessage());
            $this->assertStringContainsString('api.users.write', $e->getMessage());
        }

        $this->assertTrue($threw, 'Self-edit with access payload must throw ForbiddenException.');

        // Defense in depth: even if the exception path were skipped, the user's
        // access map must not have been mutated to grant super-admin.
        $access = $user->get('access');
        $this->assertArrayNotHasKey('admin', $access ?? [], 'admin.* must not be added by the rejected request');
        $this->assertArrayNotHasKey('super', ($access['api'] ?? []), 'api.super must not be added by the rejected request');
    }

    #[Test]
    public function self_edit_with_state_payload_is_rejected_for_low_priv_user(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]],
            'state'  => 'enabled',
        ]);

        $controller = $this->buildController($user);

        $this->expectException(ForbiddenException::class);
        $controller->update($this->makeRequest($user, 'user1', ['state' => 'disabled']));
    }

    #[Test]
    public function self_edit_of_profile_fields_succeeds_for_low_priv_user(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access'   => ['api' => ['access' => true]],
            'email'    => 'old@example.com',
            'fullname' => 'Old Name',
        ]);

        $controller = $this->buildController($user);

        $controller->update($this->makeRequest($user, 'user1', [
            'email'    => 'new@example.com',
            'fullname' => 'New Name',
            'title'    => 'Editor',
            'language' => 'en',
        ]));

        $this->assertSame('new@example.com', $user->get('email'));
        $this->assertSame('New Name', $user->get('fullname'));
        $this->assertSame('Editor', $user->get('title'));
        $this->assertSame('en', $user->get('language'));
    }

    #[Test]
    public function admin_can_update_access_field_on_other_user(): void
    {
        // Nested access map so PermissionResolver sees api.users.write as
        // granted (the test mock's get() doesn't traverse dot notation, so
        // the flat-key shortcut wouldn't work for the resolver).
        $admin = TestHelper::createMockUser('admin', [
            'access' => ['api' => ['access' => true, 'users' => ['write' => true]]],
        ]);
        $target = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]],
        ]);

        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => ['twofa_enabled' => false]],
        ]);
        $tempDir = $this->tempDir;
        $locator = new class ($tempDir) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
            {
                if (str_starts_with($uri, 'cache://')) return $this->base . '/cache';
                return $this->base;
            }
        };
        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => $locator,
            'accounts'    => TestHelper::createMockAccounts(['admin' => $admin, 'user1' => $target]),
            'permissions' => new Permissions(),
        ]);
        $controller = new UsersController(\Grav\Common\Grav::instance(), $config);

        $newAccess = ['api' => ['access' => true, 'users' => ['read' => true]]];
        $controller->update($this->makeRequest($admin, 'user1', ['access' => $newAccess]));

        $this->assertSame($newAccess, $target->get('access'));
    }

    #[Test]
    public function user_with_users_write_can_self_edit_access_field(): void
    {
        // A user-manager editing their own profile is allowed to touch `access`
        // because they already hold api.users.write.
        $manager = TestHelper::createMockUser('manager', [
            'access' => ['api' => ['access' => true, 'users' => ['write' => true]]],
        ]);

        $controller = $this->buildController($manager);

        $newAccess = ['api' => ['access' => true, 'users' => ['write' => true, 'read' => true]]];
        $controller->update($this->makeRequest($manager, 'manager', ['access' => $newAccess]));

        $this->assertSame($newAccess, $manager->get('access'));
    }
}
