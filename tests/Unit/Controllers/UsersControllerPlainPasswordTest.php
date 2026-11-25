<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\AuthController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * An account written by hand or brought in by a WordPress or Drupal import keeps a
 * plain-text `password` until its owner first signs in. The API saves such an
 * account (which hashes and removes the password) as soon as it is about to
 * return it, and leaves accounts without one alone.
 */
#[CoversClass(UsersController::class)]
class UsersControllerPlainPasswordTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function controller(): UsersController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        $this->warnings = [];
        TestHelper::createMockGrav([
            'config'      => $config,
            'permissions' => new Permissions(),
            'log'         => new class ($this->warnings) {
                /** @var list<string> */
                private array $sink;
                /** @param list<string> $sink */
                public function __construct(array &$sink) { $this->sink = &$sink; }
                public function warning(string $message): void { $this->sink[] = $message; }
            },
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /**
     * An account whose save() does what core's does: hash a plain `password` or
     * `password1` into `hashed_password` and remove it. Counts the saves, and
     * can be told to fail them.
     */
    private function account(array $data, bool $failSave = false, bool $exists = true): object
    {
        return new class ($data, $failSave, $exists) implements UserInterface {
            public readonly string $username;
            public int $saves = 0;

            public function __construct(private array $data, private readonly bool $failSave, private readonly bool $existsFlag)
            {
                $this->username = 'imported';
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->data[$key] ?? $default;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function save(): void
            {
                $this->saves++;
                if ($this->failSave) {
                    throw new \RuntimeException('User account has been changed since it was loaded');
                }
                $plain = $this->data['password'] ?? $this->data['password1'] ?? null;
                if ($plain !== null && $plain !== '') {
                    $this->data['hashed_password'] = password_hash((string) $plain, PASSWORD_DEFAULT);
                }
                unset($this->data['password'], $this->data['password1'], $this->data['password2']);
            }

            public function exists(): bool
            {
                return $this->existsFlag;
            }

            public function getAvatarImage(): ?object
            {
                return null;
            }

            public function blueprints(): ?object
            {
                return null;
            }
        };
    }

    private function serialize(UsersController $c, object $user): array
    {
        $m = new ReflectionMethod($c, 'serializeUser');
        return $m->invoke($c, $user);
    }

    #[Test]
    public function an_account_with_a_plain_password_is_hashed_before_it_is_returned(): void
    {
        $user = $this->account(['email' => 'a@example.com', 'password' => 'Operator-Plain-77']);

        $data = $this->serialize($this->controller(), $user);

        $this->assertSame(1, $user->saves);
        $this->assertNull($user->get('password'));
        $this->assertNotEmpty($user->get('hashed_password'));
        $this->assertTrue(password_verify('Operator-Plain-77', $user->get('hashed_password')));
        $this->assertSame('a@example.com', $data['email']);
        $this->assertArrayNotHasKey('password', $data);
    }

    #[Test]
    public function a_plain_password1_is_hashed_too(): void
    {
        $user = $this->account(['password1' => 'Operator-Plain-77', 'password2' => 'Operator-Plain-77']);

        $this->serialize($this->controller(), $user);

        $this->assertSame(1, $user->saves);
        $this->assertNull($user->get('password1'));
        $this->assertNull($user->get('password2'));
        $this->assertTrue(password_verify('Operator-Plain-77', $user->get('hashed_password')));
    }

    #[Test]
    public function an_account_without_a_plain_password_is_not_saved(): void
    {
        $c = $this->controller();

        $hashed = $this->account(['hashed_password' => '$2y$10$abcdefghijklmnopqrstuu']);
        $empty = $this->account(['password' => '', 'password1' => null]);
        $this->serialize($c, $hashed);
        $this->serialize($c, $empty);

        $this->assertSame(0, $hashed->saves);
        $this->assertSame(0, $empty->saves);
    }

    #[Test]
    public function an_account_that_does_not_exist_yet_is_not_saved(): void
    {
        $user = $this->account(['password' => 'Operator-Plain-77'], exists: false);

        $this->serialize($this->controller(), $user);

        $this->assertSame(0, $user->saves);
    }

    #[Test]
    public function a_failed_save_is_logged_and_the_account_is_still_returned(): void
    {
        $user = $this->account(['email' => 'a@example.com', 'password' => 'Operator-Plain-77'], failSave: true);

        $data = $this->serialize($this->controller(), $user);

        $this->assertSame(1, $user->saves);
        $this->assertSame('a@example.com', $data['email']);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('imported', $this->warnings[0]);
        $this->assertStringNotContainsString('Operator-Plain-77', $this->warnings[0]);
    }

    #[Test]
    public function the_profile_returned_for_the_signed_in_account_hashes_it_too(): void
    {
        $this->controller();
        $user = $this->account(['password' => 'Operator-Plain-77']);

        $auth = new AuthController(\Grav\Common\Grav::instance(), new Config([]));
        $m = new ReflectionMethod($auth, 'buildUserProfile');
        try {
            $m->invoke($auth, $user);
        } catch (\Throwable $e) {
            // The permission lookup needs more of Grav than the tests provide.
        }

        $this->assertSame(1, $user->saves);
        $this->assertNull($user->get('password'));
    }
}
