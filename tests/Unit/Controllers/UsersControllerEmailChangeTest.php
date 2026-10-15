<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A user's own email change waits for confirmation when the Login plugin can
 * confirm it, and saves at once everywhere else.
 */
#[CoversClass(UsersController::class)]
class UsersControllerEmailChangeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_users_email_change_test_' . uniqid();
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

    /**
     * A stand-in for the Login plugin's service with the methods the
     * controller uses. Records what it was asked to do.
     */
    private function fakeLogin(bool $taken = false, bool $limited = false): object
    {
        return new class ($taken, $limited) {
            /** @var list<string> */
            public array $requested = [];
            public int $cleared = 0;
            public int $registered = 0;

            public function __construct(private readonly bool $taken, private readonly bool $limited) {}

            public function requestEmailChange(UserInterface $user, string $email): bool
            {
                $this->requested[] = $email;
                $user->set('email_pending', $email);

                return true;
            }

            public function clearEmailChange(UserInterface $user): void
            {
                $this->cleared++;
                $user->set('email_pending', null);
            }

            public function emailBelongsToAnother(UserInterface $user, string $email): bool
            {
                return $this->taken;
            }

            public function getRateLimiter(string $context): object
            {
                $limited = $this->limited;
                $self = $this;

                return new class ($limited, $self) {
                    public function __construct(private readonly bool $limited, private readonly object $login) {}

                    public function hasReachedLimit(string $key): bool
                    {
                        return $this->limited;
                    }

                    public function getInterval(): int
                    {
                        return 60;
                    }

                    public function registerRateLimitedAction(string $key): void
                    {
                        $this->login->registered++;
                    }
                };
            }
        };
    }

    /**
     * @param array<string, object> $users
     * @param array<string, mixed>  $login plugins.login settings
     */
    private function buildController(array $users, ?object $login, array $loginConfig = []): UsersController
    {
        $tempDir = $this->tempDir;

        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => $loginConfig + ['twofa_enabled' => false]],
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

    private function request(string $method, UserInterface $caller, string $target): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: $method,
            path: '/api/v1/users/' . $target . '/email-change',
            attributes: [
                'api_user'     => $caller,
                'route_params' => ['username' => $target],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    private function member(string $name = 'user1'): UserInterface
    {
        return TestHelper::createMockUser($name, [
            'access'   => ['api' => ['access' => true]],
            'email'    => $name . '@example.com',
            'fullname' => 'Old Name',
        ]);
    }

    private function manager(): UserInterface
    {
        return TestHelper::createMockUser('manager', [
            'access' => ['api' => ['access' => true, 'users' => ['write' => true]]],
            'email'  => 'manager@example.com',
        ]);
    }

    #[Test]
    public function own_email_change_goes_pending_and_the_old_address_stays(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $response = $controller->update($this->patch($user, 'user1', ['email' => 'new@example.com']));
        $body = $this->decode($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user1@example.com', $user->get('email'));
        $this->assertSame(['new@example.com'], $login->requested);
        $this->assertSame('new@example.com', $body['data']['email_pending']);
        $this->assertSame('user1@example.com', $body['data']['email']);
        $this->assertStringContainsString('new@example.com', $body['meta']['message']);
        $this->assertSame(1, $login->registered);
    }

    #[Test]
    public function a_user_manager_changing_their_own_email_waits_too(): void
    {
        $manager = $this->manager();
        $login = $this->fakeLogin();
        $controller = $this->buildController(['manager' => $manager], $login);

        $controller->update($this->patch($manager, 'manager', ['email' => 'new@example.com']));

        $this->assertSame('manager@example.com', $manager->get('email'));
        $this->assertSame(['new@example.com'], $login->requested);
    }

    #[Test]
    public function other_fields_in_the_same_request_still_save(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], $this->fakeLogin());

        $controller->update($this->patch($user, 'user1', [
            'email'    => 'new@example.com',
            'fullname' => 'New Name',
            'title'    => 'Editor',
        ]));

        $this->assertSame('New Name', $user->get('fullname'));
        $this->assertSame('Editor', $user->get('title'));
        $this->assertSame('user1@example.com', $user->get('email'));
    }

    #[Test]
    public function sending_back_the_unchanged_email_changes_nothing(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $response = $controller->update($this->patch($user, 'user1', [
            'email'    => 'USER1@example.com',
            'fullname' => 'New Name',
        ]));

        $this->assertSame([], $login->requested);
        $this->assertArrayNotHasKey('meta', $this->decode($response));
        $this->assertSame('New Name', $user->get('fullname'));
    }

    #[Test]
    public function admin_changing_another_users_email_saves_at_once(): void
    {
        $manager = $this->manager();
        $target = $this->member('emailtest2');
        $login = $this->fakeLogin();
        $controller = $this->buildController(['manager' => $manager, 'emailtest2' => $target], $login);

        $response = $controller->update($this->patch($manager, 'emailtest2', ['email' => 'changed@example.com']));

        $this->assertSame('changed@example.com', $target->get('email'));
        $this->assertSame([], $login->requested);
        $this->assertArrayNotHasKey('email_pending', $this->decode($response)['data']);
    }

    #[Test]
    public function admin_edit_clears_a_change_waiting_on_that_account(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('emailtest2', [
            'access'        => ['api' => ['access' => true]],
            'email'         => 'emailtest2@example.com',
            'email_pending' => 'later@example.com',
        ]);
        $login = $this->fakeLogin();
        $controller = $this->buildController(['manager' => $manager, 'emailtest2' => $target], $login);

        $controller->update($this->patch($manager, 'emailtest2', ['email' => 'changed@example.com']));

        $this->assertSame(1, $login->cleared);
        $this->assertNull($target->get('email_pending'));
    }

    #[Test]
    public function without_the_login_plugin_the_email_saves_at_once(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], null);

        $response = $controller->update($this->patch($user, 'user1', ['email' => 'new@example.com']));

        $this->assertSame('new@example.com', $user->get('email'));
        $this->assertArrayNotHasKey('meta', $this->decode($response));
    }

    #[Test]
    public function an_older_login_plugin_without_the_method_saves_at_once(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], new \stdClass());

        $controller->update($this->patch($user, 'user1', ['email' => 'new@example.com']));

        $this->assertSame('new@example.com', $user->get('email'));
    }

    #[Test]
    public function with_the_setting_off_the_email_saves_at_once(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin();
        $controller = $this->buildController(
            ['user1' => $user],
            $login,
            ['profile' => ['verify_email_change' => false]],
        );

        $controller->update($this->patch($user, 'user1', ['email' => 'new@example.com']));

        $this->assertSame('new@example.com', $user->get('email'));
        $this->assertSame([], $login->requested);
    }

    #[Test]
    public function an_address_that_belongs_to_another_account_is_refused_and_nothing_saves(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin(taken: true);
        $controller = $this->buildController(['user1' => $user], $login);

        try {
            $controller->update($this->patch($user, 'user1', ['email' => 'taken@example.com', 'fullname' => 'New Name']));
            $this->fail('A taken address must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame([], $login->requested);
        $this->assertSame('Old Name', $user->get('fullname'));
    }

    #[Test]
    public function a_taken_address_gets_the_quiet_answer_when_activation_email_is_on(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin(taken: true);
        $controller = $this->buildController(
            ['user1' => $user],
            $login,
            ['user_registration' => ['options' => ['send_activation_email' => true]]],
        );

        $response = $controller->update($this->patch($user, 'user1', ['email' => 'taken@example.com']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('taken@example.com', $this->decode($response)['data']['email_pending']);
    }

    #[Test]
    public function the_rate_limit_returns_429_and_nothing_saves(): void
    {
        $user = $this->member();
        $login = $this->fakeLogin(limited: true);
        $controller = $this->buildController(['user1' => $user], $login);

        try {
            $controller->update($this->patch($user, 'user1', ['email' => 'new@example.com', 'fullname' => 'New Name']));
            $this->fail('The limit must be enforced.');
        } catch (TooManyRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }

        $this->assertSame('Old Name', $user->get('fullname'));
    }

    #[Test]
    public function a_malformed_address_is_refused(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], $this->fakeLogin());

        $this->expectException(ValidationException::class);
        $controller->update($this->patch($user, 'user1', ['email' => 'not-an-address']));
    }

    #[Test]
    public function cancel_drops_the_waiting_change(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]], 'email' => 'user1@example.com', 'email_pending' => 'new@example.com',
        ]);
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $response = $controller->cancelEmailChange($this->request('DELETE', $user, 'user1'));

        $this->assertSame(1, $login->cleared);
        $this->assertArrayNotHasKey('email_pending', $this->decode($response)['data']);
    }

    #[Test]
    public function resend_mails_the_link_again(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]], 'email' => 'user1@example.com', 'email_pending' => 'new@example.com',
        ]);
        $login = $this->fakeLogin();
        $controller = $this->buildController(['user1' => $user], $login);

        $response = $controller->resendEmailChange($this->request('POST', $user, 'user1'));

        $this->assertSame(['new@example.com'], $login->requested);
        $this->assertStringContainsString('new@example.com', $this->decode($response)['meta']['message']);
    }

    #[Test]
    public function cancel_and_resend_are_for_your_own_account_only(): void
    {
        $manager = $this->manager();
        $target = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]], 'email' => 'user1@example.com', 'email_pending' => 'new@example.com',
        ]);
        $controller = $this->buildController(['manager' => $manager, 'user1' => $target], $this->fakeLogin());

        $this->expectException(ForbiddenException::class);
        $controller->cancelEmailChange($this->request('DELETE', $manager, 'user1'));
    }

    #[Test]
    public function cancel_with_nothing_waiting_is_a_404(): void
    {
        $user = $this->member();
        $controller = $this->buildController(['user1' => $user], $this->fakeLogin());

        $this->expectException(NotFoundException::class);
        $controller->cancelEmailChange($this->request('DELETE', $user, 'user1'));
    }
}
