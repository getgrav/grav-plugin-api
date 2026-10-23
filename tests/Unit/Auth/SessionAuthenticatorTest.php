<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\SessionAuthenticator;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression for GHSA-7qfj-82q8-frw6: the session authenticator refreshed
 * `access` from disk but never read `state`, and kept the stale session copy
 * when the account could not be loaded, so disabling or deleting an account
 * left its browser sessions working until they expired.
 */
#[CoversClass(SessionAuthenticator::class)]
class SessionAuthenticatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function an_enabled_account_is_accepted_with_fresh_access(): void
    {
        $fresh = TestHelper::createMockUser('editor', [
            'state'  => 'enabled',
            'access' => ['api' => ['access' => true, 'pages' => ['read' => true]]],
        ]);

        $user = $this->authenticate($this->accounts(['editor' => $fresh]));

        self::assertNotNull($user);
        self::assertSame($fresh->get('access'), $user->get('access'));
    }

    #[Test]
    public function an_account_without_a_state_is_treated_as_enabled(): void
    {
        $fresh = TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true]]]);

        self::assertNotNull($this->authenticate($this->accounts(['editor' => $fresh])));
    }

    #[Test]
    public function a_disabled_account_is_rejected(): void
    {
        $fresh = TestHelper::createMockUser('editor', [
            'state'  => 'disabled',
            'access' => ['api' => ['access' => true]],
        ]);

        self::assertNull($this->authenticate($this->accounts(['editor' => $fresh])));
    }

    #[Test]
    public function a_deleted_account_is_rejected(): void
    {
        self::assertNull($this->authenticate($this->accounts([])));
    }

    #[Test]
    public function an_account_that_cannot_be_loaded_is_rejected(): void
    {
        $accounts = new class {
            public function load(string $username): object
            {
                throw new \RuntimeException('account storage unavailable');
            }
        };

        self::assertNull($this->authenticate($accounts));
    }

    /**
     * @param array<string, UserInterface> $users
     */
    private function accounts(array $users): object
    {
        return TestHelper::createMockAccounts($users);
    }

    #[Test]
    public function a_session_from_before_a_password_change_is_rejected(): void
    {
        $fresh = TestHelper::createMockUser('editor', [
            'access'                 => ['api' => ['access' => true]],
            'api_tokens_valid_after' => 2000,
        ]);

        self::assertNull($this->authenticate($this->accounts(['editor' => $fresh]), ['api_tokens_valid_after' => 1000]));
        self::assertNull($this->authenticate($this->accounts(['editor' => $fresh])));
    }

    #[Test]
    public function the_session_that_changed_the_password_is_accepted(): void
    {
        $fresh = TestHelper::createMockUser('editor', [
            'access'                 => ['api' => ['access' => true]],
            'api_tokens_valid_after' => 2000,
        ]);

        self::assertNotNull($this->authenticate($this->accounts(['editor' => $fresh]), ['api_tokens_valid_after' => 2000]));
    }

    /** @param array<string, mixed> $sessionData */
    private function authenticate(object $accounts, array $sessionData = []): ?UserInterface
    {
        // The serialized session copy still carries the permissions it had at
        // login, which is exactly what must not survive a revocation.
        $sessionUser = $this->sessionUser('editor', $sessionData + [
            'state'  => 'enabled',
            'access' => ['api' => ['access' => true, 'super' => true]],
        ]);

        $session = new class ($sessionUser) {
            public function __construct(public ?object $user) {}
            public function isStarted(): bool { return true; }
        };

        TestHelper::createMockGrav(['session' => $session, 'accounts' => $accounts]);

        return (new SessionAuthenticator(Grav::instance()))
            ->authenticate(TestHelper::createMockRequest(method: 'GET', path: '/api/v1/me'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function sessionUser(string $username, array $data): UserInterface
    {
        $data['username'] = $username;

        return new class ($data) implements UserInterface {
            public bool $authenticated = true;
            public bool $authorized = true;

            /** @param array<string, mixed> $data */
            public function __construct(private array $data) {}
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function save(): void {}
            public function exists(): bool { return true; }
        };
    }
}
