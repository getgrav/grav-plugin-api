<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\AdminProxy;
use Grav\Plugin\Api\ApiRouter;
use Grav\Plugin\Api\Controllers\AuthController;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Services\BlueprintPathResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for PHP 8.6 compatibility fixes in grav-plugin-api (PHP 8.3+ compatible).
 */
class Php86CompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function api_router_rejects_percent_encoded_and_raw_nul_byte_route_params(): void
    {
        Grav::resetInstance();
        $router = new ApiRouter(Grav::instance(), new Config());
        $handleRoute = new \ReflectionMethod(ApiRouter::class, 'handleRoute');

        foreach (['foo%00.zip', "foo\0.zip"] as $bad) {
            $request = TestHelper::createMockRequest('GET', '/api/v1/system/backups/foo/download');
            try {
                $handleRoute->invoke(
                    $router,
                    $request,
                    [SystemController::class, 'downloadBackup'],
                    ['filename' => $bad],
                );
                self::fail('Expected ValidationException for route param containing NUL byte');
            } catch (ValidationException $e) {
                self::assertSame('Invalid route parameter.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function system_controller_rejects_nul_byte_backup_filenames(): void
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['backups'] = new \stdClass();
        $controller = new SystemController($grav, new Config());

        $superAdmin = TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]);
        $request = TestHelper::createMockRequest(
            method: 'DELETE',
            path: '/api/v1/system/backups/foo.zip',
            attributes: [
                'api_user' => $superAdmin,
                'route_params' => ['filename' => "foo\0.zip"],
            ],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid backup filename.');

        $controller->deleteBackup($request);
    }

    #[Test]
    public function admin_proxy_get_page_returns_null_for_nul_byte_paths(): void
    {
        $proxy = (new \ReflectionClass(AdminProxy::class))->newInstanceWithoutConstructor();

        self::assertNull($proxy->getPage('/blog/post%00'));
        self::assertNull($proxy->getPage("/blog/post\0"));
    }

    #[Test]
    public function blueprint_path_resolver_rejects_nul_byte_scope(): void
    {
        Grav::resetInstance();
        $resolver = new BlueprintPathResolver(Grav::instance());

        self::assertNull($resolver->resolveScopeRoot("plugins/a\0b"));
        self::assertNull($resolver->resolveScopeRoot('plugins\\a'));

        $this->expectException(ValidationException::class);
        $resolver->resolve('self@:images', "plugins/a\0b");
    }

    #[Test]
    public function auth_controller_token_rejects_nul_byte_username_without_500(): void
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $config = new Config();
        $controller = new AuthController($grav, $config);

        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/auth/token',
            attributes: [
                'json_body' => ['username' => "ab\0c", 'password' => 'secret'],
            ],
        );

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid username or password.');

        $controller->token($request);
    }

    #[Test]
    public function auth_controller_reset_password_rejects_nul_byte_username_with_422(): void
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $config = new Config();
        $controller = new AuthController($grav, $config);

        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/auth/reset-password',
            attributes: [
                'json_body' => ['username' => "ab\0c", 'token' => 'tok', 'password' => 'Secret123!'],
            ],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid or expired reset link.');

        $controller->resetPassword($request);
    }

    #[Test]
    public function users_controller_create_rejects_nul_byte_username_with_422(): void
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $controller = new UsersController($grav, new Config());

        $superAdmin = TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]);
        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/users',
            attributes: [
                'api_user' => $superAdmin,
                'json_body' => [
                    'username' => "ab\0cd",
                    'password' => 'Secret123!',
                    'email' => 'test@example.com',
                ],
            ],
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid username format.');

        $controller->create($request);
    }
}
