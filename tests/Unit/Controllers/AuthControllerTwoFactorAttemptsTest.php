<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Controllers\AuthController;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class AuthControllerTwoFactorAttemptsTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        $dir = getenv('LOGIN_PLUGIN_DIR');
        if (!class_exists(TwoFactorAuth::class) && $dir && is_file($dir . '/vendor/autoload.php')) {
            require_once $dir . '/vendor/autoload.php';
        }
    }

    protected function setUp(): void
    {
        if (!class_exists(TwoFactorAuth::class)) {
            self::markTestSkipped('Set LOGIN_PLUGIN_DIR to the Login plugin.');
        }
        $this->dir = sys_get_temp_dir() . '/api-twofa-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/plugins', 0700, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->dir)) return;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function testChallengesShareTheStoredAccountCounter(): void
    {
        $limiter = new class {
            public array $keys = [];
            public int $attempts = 0;
            public function hasReachedLimit(string $key): bool { $this->keys[] = $key; return $this->attempts >= 2; }
            public function registerRateLimitedAction(string $key): void { $this->keys[] = $key; $this->attempts++; }
            public function getRetryAfter(string $key): int { return 120; }
            public function getInterval(): int { return 2; }
            public function resetRateLimit(string $key): void { $this->keys[] = $key; $this->attempts = 0; }
        };
        [$controller, $jwt, $user, $login] = $this->fixture($limiter);
        for ($i = 0; $i < 2; $i++) {
            $token = $jwt->generateChallengeToken($user, '2fa_challenge');
            try {
                $controller->verify2fa(TestHelper::createMockRequest(attributes: ['json_body' => ['challenge_token' => $token, 'code' => 'invalid']]));
                self::fail('Expected a refused code.');
            } catch (UnauthorizedException) {
                self::assertSame($i + 1, $limiter->attempts);
            }
            // A password-only login clears only the login counter.
            $login->resetLoginRateLimit('stored');
        }
        $token = $jwt->generateChallengeToken($user, '2fa_challenge');
        try {
            $controller->verify2fa(TestHelper::createMockRequest(attributes: ['json_body' => ['challenge_token' => $token, 'code' => 'invalid']]));
            self::fail('Expected the shared counter to refuse another challenge.');
        } catch (TooManyRequestsException $e) {
            self::assertSame(429, $e->getStatusCode());
            self::assertSame(['stored'], array_values(array_unique($limiter->keys)));
            self::assertSame(['twofa_attempts'], array_values(array_unique($login->contexts)));
        }
    }

    public function testSuccessfulVerificationClearsTheSecondFactorCounter(): void
    {
        $limiter = new class {
            public array $reset = [];
            public function hasReachedLimit(string $key): bool { return false; }
            public function resetRateLimit(string $key): void { $this->reset[] = $key; }
        };
        [$controller, $jwt, $user] = $this->fixture($limiter);
        $code = (new TwoFactorAuth())->get2FA()->getCode($user->get('twofa_secret'));
        $token = $jwt->generateChallengeToken($user, '2fa_challenge');
        $response = $controller->verify2fa(TestHelper::createMockRequest(attributes: ['json_body' => ['challenge_token' => $token, 'code' => $code]]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['stored'], $limiter->reset);
    }

    private function fixture(object $limiter): array
    {
        $user = TestHelper::createMockUser('stored', ['state' => 'enabled', 'twofa_secret' => (new TwoFactorAuth())->createSecret()]);
        $login = new class ($limiter) {
            public array $contexts = [];
            public function __construct(private object $limiter) {}
            public function getRateLimiter(string $context): object { $this->contexts[] = $context; return $this->limiter; }
            public function checkLoginRateLimit(string $key): int { return 0; }
            public function resetLoginRateLimit(string $key): void {}
        };
        $locator = new class ($this->dir) {
            public function __construct(private string $dir) {}
            public function findResource(...$args): string { return $this->dir; }
            public function findResources(...$args): array { return [$this->dir]; }
        };
        $config = new Config();
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator, 'accounts' => TestHelper::createMockAccounts(['stored' => $user]), 'login' => $login]);
        $controller = new class ($grav, $config) extends AuthController {
            protected function issueTokenPair(JwtAuthenticator $jwt, UserInterface $user): ResponseInterface { return ApiResponse::ok([]); }
        };
        return [$controller, new JwtAuthenticator($grav, $config), $user, $login];
    }
}
