<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A signed-in session can't remove or replace an active second factor on its
 * own account without a current code: POST /users/{username}/2fa (a new
 * secret, which switches 2FA off) and POST /users/{username}/2fa/disable both
 * ask for one, whatever permissions the caller holds. An admin acting on
 * someone else's account (lost-device recovery) needs none.
 *
 * Uses the Login plugin's real 2FA helper, found next to this plugin or
 * through LOGIN_PLUGIN_DIR; skipped when it isn't there.
 */
#[CoversClass(UsersController::class)]
class UsersControllerTwoFactorCodeTest extends TestCase
{
    private TwoFactorAuth $twoFa;

    public static function setUpBeforeClass(): void
    {
        if (class_exists(TwoFactorAuth::class)) {
            return;
        }
        $root = dirname(__DIR__, 3);
        foreach ([getenv('LOGIN_PLUGIN_DIR') ?: null, $root . '/../login', $root . '/../grav-plugin-login'] as $dir) {
            if ($dir && is_file($dir . '/vendor/autoload.php')) {
                require_once $dir . '/vendor/autoload.php';
                break;
            }
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(TwoFactorAuth::class)) {
            $this->markTestSkipped('The Login plugin is not available; set LOGIN_PLUGIN_DIR.');
        }
        $this->twoFa = new TwoFactorAuth();
    }

    /** A stand-in for the Login plugin's twofa_attempts limiter. */
    private function fakeLogin(bool $limited = false): object
    {
        return new class ($limited) {
            public int $registered = 0;
            public int $reset = 0;

            public function __construct(private readonly bool $limited) {}

            public function getRateLimiter(string $context): object
            {
                $login = $this;

                return new class ($login) {
                    public function __construct(private readonly object $login) {}
                    public function hasReachedLimit(string $key): bool { return $this->login->isLimited(); }
                    public function getRetryAfter(string $key): int { return 300; }
                    public function getInterval(): int { return 10; }
                    public function registerRateLimitedAction(string $key): void { $this->login->registered++; }
                    public function resetRateLimit(string $key): void { $this->login->reset++; }
                };
            }

            public function isLimited(): bool
            {
                return $this->limited;
            }
        };
    }

    private function controller(?object $login, UserInterface ...$users): UsersController
    {
        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ]],
            'site' => ['title' => 'Test'],
        ]);

        $byName = [];
        foreach ($users as $u) {
            $byName[$u->username] = $u;
        }

        $services = [
            'config'      => $config,
            'accounts'    => TestHelper::createMockAccounts($byName),
            'permissions' => new Permissions(),
        ];
        if ($login !== null) {
            $services['login'] = $login;
        }
        TestHelper::createMockGrav($services);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function request(UserInterface $caller, string $target, string $path, array $body = []): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/users/' . $target . $path,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user'     => $caller,
                'json_body'    => $body,
                'route_params' => ['username' => $target],
            ],
        );
    }

    /** An account with 2FA on. A manager holds api.users.write as well. */
    private function account(string $name, bool $manager = false, bool $active = true): UserInterface
    {
        $access = ['api' => ['access' => true]];
        if ($manager) {
            $access['api']['users'] = ['read' => true, 'write' => true];
        }

        return TestHelper::createMockUser($name, [
            'access'        => $access,
            'twofa_enabled' => $active,
            'twofa_secret'  => $this->twoFa->createSecret(),
        ]);
    }

    private function codeFor(UserInterface $user): string
    {
        return $this->twoFa->get2FA()->getCode(str_replace(' ', '', (string) $user->get('twofa_secret')));
    }

    /** @return array<int, array{field: string, message: string}> */
    private function refusal(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->getValidationErrors();
        }
        $this->fail('Expected a 422.');
    }

    #[Test]
    public function a_new_secret_on_your_own_active_2fa_needs_a_code(): void
    {
        $user = $this->account('alice');
        $secret = $user->get('twofa_secret');
        $controller = $this->controller($this->fakeLogin(), $user);

        $errors = $this->refusal(fn () => $controller->generate2fa($this->request($user, 'alice', '/2fa')));

        $this->assertSame('code', $errors[0]['field']);
        $this->assertTrue($user->get('twofa_enabled'));
        $this->assertSame($secret, $user->get('twofa_secret'));
    }

    #[Test]
    public function a_wrong_code_is_refused_and_counted(): void
    {
        $user = $this->account('alice');
        $login = $this->fakeLogin();
        $controller = $this->controller($login, $user);

        $this->refusal(fn () => $controller->generate2fa($this->request($user, 'alice', '/2fa', ['code' => '000000'])));
        $this->refusal(fn () => $controller->disable2fa($this->request($user, 'alice', '/2fa/disable', ['code' => '000000'])));

        $this->assertSame(2, $login->registered);
        $this->assertTrue($user->get('twofa_enabled'));
    }

    #[Test]
    public function too_many_wrong_codes_answer_429(): void
    {
        $user = $this->account('alice');
        $controller = $this->controller($this->fakeLogin(true), $user);

        $this->expectException(TooManyRequestsException::class);
        $controller->generate2fa($this->request($user, 'alice', '/2fa', ['code' => $this->codeFor($user)]));
    }

    #[Test]
    public function the_current_code_lets_you_replace_the_secret(): void
    {
        $user = $this->account('alice');
        $old = $user->get('twofa_secret');
        $login = $this->fakeLogin();
        $controller = $this->controller($login, $user);

        $response = $controller->generate2fa($this->request($user, 'alice', '/2fa', ['code' => $this->codeFor($user)]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotSame($old, $user->get('twofa_secret'));
        $this->assertFalse($user->get('twofa_enabled'));
        $this->assertSame(1, $login->reset);
    }

    #[Test]
    public function first_time_setup_needs_no_code(): void
    {
        $user = $this->account('alice', active: false);
        $controller = $this->controller($this->fakeLogin(), $user);

        $response = $controller->generate2fa($this->request($user, 'alice', '/2fa'));

        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_user_manager_turning_off_their_own_2fa_needs_a_code_too(): void
    {
        $manager = $this->account('manager', manager: true);
        $controller = $this->controller($this->fakeLogin(), $manager);

        $errors = $this->refusal(fn () => $controller->disable2fa($this->request($manager, 'manager', '/2fa/disable')));
        $this->assertSame('code', $errors[0]['field']);
        $this->assertTrue($manager->get('twofa_enabled'));

        $this->refusal(fn () => $controller->generate2fa($this->request($manager, 'manager', '/2fa')));
        $this->assertTrue($manager->get('twofa_enabled'));

        $controller->disable2fa($this->request($manager, 'manager', '/2fa/disable', ['code' => $this->codeFor($manager)]));
        $this->assertFalse($manager->get('twofa_enabled'));
    }

    #[Test]
    public function an_admin_resets_someone_elses_2fa_without_a_code(): void
    {
        $manager = $this->account('manager', manager: true);
        $user = $this->account('alice');
        $controller = $this->controller($this->fakeLogin(), $manager, $user);

        $controller->generate2fa($this->request($manager, 'alice', '/2fa'));
        $this->assertFalse($user->get('twofa_enabled'));

        $other = $this->account('bob');
        $controller = $this->controller($this->fakeLogin(), $manager, $other);
        $controller->disable2fa($this->request($manager, 'bob', '/2fa/disable'));
        $this->assertFalse($other->get('twofa_enabled'));
    }
}
