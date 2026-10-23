<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Grav\Common\Config\Config;
use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Changing your own password through PATCH /users/{username} takes the current
 * one, counted against the sign-in limits; an admin setting someone else's
 * does not. Either way the account is signed out everywhere else, and someone
 * changing their own with a JWT gets a fresh token pair.
 */
#[CoversClass(UsersController::class)]
class UsersControllerPasswordChangeTest extends TestCase
{
    private const SECRET = 'test-secret-for-password-change-0123456789';
    private const OLD = 'OldPassw0rd!';
    private const NEW = 'NewPassw0rd!';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_users_password_change_test_' . uniqid();
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
        @mkdir($this->tempDir . '/plugins', 0775, true);
        file_put_contents($this->tempDir . '/plugins/api-private.php', "<?php\nreturn " . var_export(self::SECRET, true) . ";\n");
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

    /**
     * A stand-in for the Login plugin's service: the sign-in limiter and the
     * sign-out helper. Records what it was asked to do.
     */
    private function fakeLogin(int $wait = 0): object
    {
        return new class ($wait) {
            public int $checked = 0;
            public int $reset = 0;
            /** @var list<array{string, bool}> */
            public array $ended = [];

            public function __construct(private readonly int $wait) {}

            public function checkLoginRateLimit(string $username): int
            {
                $this->checked++;

                return $this->wait;
            }

            public function resetLoginRateLimit(string $username): void
            {
                $this->reset++;
            }

            public function endOtherSessions(UserInterface $user, bool $keepCurrent = true): void
            {
                $this->ended[] = [$user->username, $keepCurrent];
                $user->set('api_tokens_valid_after', time());
            }
        };
    }

    /** @param array<string, object> $users */
    private function buildController(array $users, ?object $login): UsersController
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

        $services = [
            'config'      => $config,
            'locator'     => $locator,
            'accounts'    => TestHelper::createMockAccounts($users),
            'permissions' => new Permissions(),
        ];
        if ($login !== null) {
            $services['login'] = $login;
        }
        TestHelper::createMockGrav($services);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function patch(UserInterface $caller, string $target, array $body, string $method = 'jwt'): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'PATCH',
            path: '/api/v1/users/' . $target,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user'        => $caller,
                'api_auth_method' => $method,
                'json_body'       => $body,
                'route_params'    => ['username' => $target],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    /** @param array<string, mixed> $extra */
    private function member(string $name = 'user1', array $extra = []): UserInterface
    {
        return TestHelper::createMockUser($name, $extra + [
            'access'          => ['api' => ['access' => true]],
            'email'           => $name . '@example.com',
            'hashed_password' => Authentication::create(self::OLD),
        ]);
    }

    private function manager(): UserInterface
    {
        return TestHelper::createMockUser('manager', [
            'access'          => ['api' => ['access' => true, 'users' => ['write' => true]]],
            'email'           => 'manager@example.com',
            'hashed_password' => Authentication::create(self::OLD),
        ]);
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
    public function changing_your_own_password_without_the_current_one_is_refused(): void
    {
        $user = $this->member();
        $hash = $user->get('hashed_password');
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $errors = $this->refusal(fn () => $controller->update($this->patch($user, 'user1', ['password' => self::NEW])));

        $this->assertSame('current_password', $errors[0]['field']);
        $this->assertSame($hash, $user->get('hashed_password'));
        $this->assertNull($user->get('api_tokens_valid_after'));
        $this->assertSame(0, $login->checked, 'a missing password is not a guess');
    }

    #[Test]
    public function a_wrong_current_password_is_refused_and_counted(): void
    {
        $user = $this->member();
        $hash = $user->get('hashed_password');
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $errors = $this->refusal(fn () => $controller->update($this->patch($user, 'user1', [
            'password' => self::NEW,
            'current_password' => 'not-it',
        ])));

        $this->assertSame('current_password', $errors[0]['field']);
        $this->assertSame($hash, $user->get('hashed_password'));
        $this->assertSame(1, $login->checked);
        $this->assertSame(0, $login->reset);
        $this->assertSame([], $login->ended);
    }

    #[Test]
    public function the_whole_request_is_refused_with_a_wrong_current_password(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], $this->fakeLogin());

        $this->refusal(fn () => $controller->update($this->patch($user, 'user1', [
            'fullname' => 'Changed',
            'password' => self::NEW,
            'current_password' => 'not-it',
        ])));

        $this->assertNull($user->get('fullname'));
    }

    #[Test]
    public function too_many_wrong_passwords_answer_429_without_checking_another(): void
    {
        $user = $this->member();
        $hash = $user->get('hashed_password');
        $controller = $this->buildController(['user1' => $user], $this->fakeLogin(7));

        try {
            $controller->update($this->patch($user, 'user1', [
                'password' => self::NEW,
                'current_password' => self::OLD,
            ]));
            $this->fail('Expected a 429.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }

        $this->assertSame($hash, $user->get('hashed_password'));
    }

    #[Test]
    public function the_right_current_password_changes_it_and_keeps_the_caller_signed_in(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $before = time();
        $response = $controller->update($this->patch($user, 'user1', [
            'password' => self::NEW,
            'current_password' => self::OLD,
        ]));
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(password_verify(self::NEW, (string) $user->get('hashed_password')));
        $this->assertSame(1, $login->reset, 'a right password clears the count');
        $this->assertSame([['user1', true]], $login->ended);
        $this->assertGreaterThanOrEqual($before, (int) $user->get('api_tokens_valid_after'));

        // The fresh pair passes the cutoff the change just stamped.
        $tokens = $body['meta']['tokens'];
        $this->assertSame('Bearer', $tokens['token_type']);
        foreach (['access_token', 'refresh_token'] as $key) {
            $claims = JWT::decode($tokens[$key], new Key(self::SECRET, 'HS256'));
            $this->assertSame('user1', $claims->sub);
            $this->assertGreaterThanOrEqual((int) $user->get('api_tokens_valid_after'), $claims->iat);
        }
        $this->assertTrue($body['data']['has_password']);
    }

    #[Test]
    public function a_session_or_api_key_caller_gets_no_tokens(): void
    {
        foreach (['session', 'apikey'] as $method) {
            $user = $this->member();
            $controller = $this->buildController(['user1' => $user], $this->fakeLogin());

            $body = $this->decode($controller->update($this->patch($user, 'user1', [
                'password' => self::NEW,
                'current_password' => self::OLD,
            ], $method)));

            $this->assertArrayNotHasKey('tokens', $body['meta'] ?? [], $method);
        }
    }

    #[Test]
    public function a_user_manager_changing_their_own_password_needs_the_current_one_too(): void
    {
        $manager = $this->manager();
        $controller = $this->buildController(['manager' => $manager], $this->fakeLogin());

        $errors = $this->refusal(fn () => $controller->update($this->patch($manager, 'manager', ['password' => self::NEW])));

        $this->assertSame('current_password', $errors[0]['field']);
    }

    #[Test]
    public function an_account_with_no_password_sets_its_first_without_one(): void
    {
        $user = TestHelper::createMockUser('sso', [
            'access' => ['api' => ['access' => true]],
            'email'  => 'sso@example.com',
        ]);
        $login = $this->fakeLogin();
        $controller = $this->buildController(['sso' => $user], $login);

        $body = $this->decode($controller->update($this->patch($user, 'sso', ['password' => self::NEW])));

        $this->assertTrue(password_verify(self::NEW, (string) $user->get('hashed_password')));
        $this->assertSame(0, $login->checked);
        $this->assertTrue($body['data']['has_password']);
    }

    #[Test]
    public function a_plain_text_password_in_the_account_file_counts_and_is_dropped(): void
    {
        $user = TestHelper::createMockUser('legacy', [
            'access'   => ['api' => ['access' => true]],
            'email'    => 'legacy@example.com',
            'password' => self::OLD,
        ]);
        $controller = $this->buildController(['legacy' => $user], $this->fakeLogin());

        $this->refusal(fn () => $controller->update($this->patch($user, 'legacy', ['password' => self::NEW])));

        $controller->update($this->patch($user, 'legacy', [
            'password' => self::NEW,
            'current_password' => self::OLD,
        ]));

        $this->assertNull($user->get('password'));
        $this->assertTrue(password_verify(self::NEW, (string) $user->get('hashed_password')));
    }

    #[Test]
    public function an_admin_sets_someone_elses_password_without_theirs_and_signs_them_out(): void
    {
        $manager = $this->manager();
        $user = $this->member();
        $login = $this->fakeLogin();
        $controller = $this->buildController(['manager' => $manager, 'user1' => $user], $login);

        $body = $this->decode($controller->update($this->patch($manager, 'user1', ['password' => self::NEW])));

        $this->assertTrue(password_verify(self::NEW, (string) $user->get('hashed_password')));
        $this->assertSame(0, $login->checked);
        $this->assertSame([['user1', false]], $login->ended);
        $this->assertArrayNotHasKey('tokens', $body['meta'] ?? []);
    }

    #[Test]
    public function a_non_super_manager_still_cannot_set_a_super_admins_password(): void
    {
        $manager = $this->manager();
        $super = TestHelper::createMockUser('root', [
            'access'          => ['admin' => ['super' => true], 'api' => ['super' => true]],
            'hashed_password' => Authentication::create(self::OLD),
        ]);
        $controller = $this->buildController(['manager' => $manager, 'root' => $super], $this->fakeLogin());

        $this->expectException(ForbiddenException::class);
        $controller->update($this->patch($manager, 'root', ['password' => self::NEW]));
    }

    #[Test]
    public function without_the_login_plugin_the_cutoff_is_still_stamped(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], null);

        $controller->update($this->patch($user, 'user1', [
            'password' => self::NEW,
            'current_password' => self::OLD,
        ]));

        $this->assertGreaterThan(0, (int) $user->get('api_tokens_valid_after'));
    }

    #[Test]
    public function the_user_record_says_whether_the_account_has_a_password(): void
    {
        $user = $this->member();
        $sso = TestHelper::createMockUser('sso', ['access' => ['api' => ['access' => true]]]);
        $controller = $this->buildController(['user1' => $user, 'sso' => $sso], null);

        $show = fn (UserInterface $u) => $this->decode($controller->show(TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/users/' . $u->username,
            attributes: ['api_user' => $u, 'route_params' => ['username' => $u->username]],
        )))['data'];

        $this->assertTrue($show($user)['has_password']);
        $this->assertFalse($show($sso)['has_password']);
        $this->assertArrayNotHasKey('hashed_password', $show($user));
    }
}
