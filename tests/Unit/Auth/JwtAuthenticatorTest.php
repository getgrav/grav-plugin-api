<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the JwtAuthenticator.
 *
 * We subclass JwtAuthenticator to override getSecret() and getRevokedTokensFile()
 * so the tests run without a full Grav file system.
 */
#[CoversClass(JwtAuthenticator::class)]
class JwtAuthenticatorTest extends TestCase
{
    private const SECRET = 'test-jwt-secret-key-at-least-32-chars-long';
    private const ALGORITHM = 'HS256';

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_jwt_test_' . uniqid();
        @mkdir($this->tempDir, 0775, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files) {
            array_map('unlink', $files);
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function returns_null_when_no_bearer_token(): void
    {
        $authenticator = $this->buildAuthenticator([]);

        $request = TestHelper::createMockRequest();

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function returns_null_with_non_bearer_authorization(): void
    {
        $authenticator = $this->buildAuthenticator([]);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Basic dXNlcjpwYXNz'],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function authenticates_valid_access_token(): void
    {
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'alice',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function authenticates_via_x_api_token_bare_jwt(): void
    {
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'alice',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Token' => $token],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function authenticates_via_x_api_token_with_bearer_prefix(): void
    {
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'alice',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['X-API-Token' => 'Bearer ' . $token],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function x_api_token_takes_precedence_over_authorization(): void
    {
        $alice = TestHelper::createMockUser('alice');
        $bob = TestHelper::createMockUser('bob');
        $authenticator = $this->buildAuthenticator(['alice' => $alice, 'bob' => $bob]);

        $aliceToken = JWT::encode([
            'iss' => 'grav-api', 'sub' => 'alice', 'iat' => time(),
            'exp' => time() + 3600, 'type' => 'access',
        ], self::SECRET, self::ALGORITHM);
        $bobToken = JWT::encode([
            'iss' => 'grav-api', 'sub' => 'bob', 'iat' => time(),
            'exp' => time() + 3600, 'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        // X-API-Token carries Alice's JWT; Authorization carries Bob's.
        // Custom header wins (FPM-stripping hosts may drop Authorization
        // silently, so we want the survivable channel to be authoritative).
        $request = TestHelper::createMockRequest(
            headers: [
                'X-API-Token' => $aliceToken,
                'Authorization' => 'Bearer ' . $bobToken,
            ],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function rejects_expired_token(): void
    {
        $user = TestHelper::createMockUser('bob');
        $authenticator = $this->buildAuthenticator(['bob' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'bob',
            'iat' => time() - 7200,
            'exp' => time() - 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function rejects_refresh_token_as_access(): void
    {
        $user = TestHelper::createMockUser('carol');
        $authenticator = $this->buildAuthenticator(['carol' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'carol',
            'iat' => time(),
            'exp' => time() + 604800,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNull($authenticator->authenticate($request), 'Refresh tokens must not authenticate as access tokens');
    }

    #[Test]
    public function rejects_nonexistent_user(): void
    {
        $authenticator = $this->buildAuthenticator([]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'ghost',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function generate_access_token_is_valid(): void
    {
        $user = TestHelper::createMockUser('dave');
        $authenticator = $this->buildAuthenticator(['dave' => $user]);

        $token = $authenticator->generateAccessToken($user);

        self::assertNotEmpty($token);

        $decoded = JWT::decode($token, new Key(self::SECRET, self::ALGORITHM));

        self::assertSame('grav-api', $decoded->iss);
        self::assertSame('dave', $decoded->sub);
        self::assertSame('access', $decoded->type);
        self::assertGreaterThan(time(), $decoded->exp);
    }

    #[Test]
    public function generate_refresh_token_is_valid(): void
    {
        $user = TestHelper::createMockUser('eve');
        $authenticator = $this->buildAuthenticator(['eve' => $user]);

        $token = $authenticator->generateRefreshToken($user);

        self::assertNotEmpty($token);

        $decoded = JWT::decode($token, new Key(self::SECRET, self::ALGORITHM));

        self::assertSame('grav-api', $decoded->iss);
        self::assertSame('eve', $decoded->sub);
        self::assertSame('refresh', $decoded->type);
        self::assertNotEmpty($decoded->jti);
        self::assertGreaterThan(time(), $decoded->exp);
    }

    #[Test]
    public function refresh_token_validation(): void
    {
        $user = TestHelper::createMockUser('frank');
        $authenticator = $this->buildAuthenticator(['frank' => $user]);

        $refreshToken = $authenticator->generateRefreshToken($user);

        $result = $authenticator->validateRefreshToken($refreshToken);

        self::assertNotNull($result);
        self::assertSame('frank', $result->username);
    }

    #[Test]
    public function refresh_token_validation_rejects_access_token(): void
    {
        $user = TestHelper::createMockUser('grace');
        $authenticator = $this->buildAuthenticator(['grace' => $user]);

        $accessToken = $authenticator->generateAccessToken($user);

        $result = $authenticator->validateRefreshToken($accessToken);

        self::assertNull($result, 'Access tokens must not be accepted as refresh tokens');
    }

    #[Test]
    public function revoke_token(): void
    {
        $user = TestHelper::createMockUser('heidi');
        $authenticator = $this->buildAuthenticator(['heidi' => $user]);

        $refreshToken = $authenticator->generateRefreshToken($user);

        // Token should be valid before revocation
        self::assertNotNull($authenticator->validateRefreshToken($refreshToken));

        // Revoke it
        $revoked = $authenticator->revokeToken($refreshToken);
        self::assertTrue($revoked);

        // Token should be rejected after revocation
        self::assertNull($authenticator->validateRefreshToken($refreshToken));
    }

    #[Test]
    public function revoke_access_token_returns_false(): void
    {
        $user = TestHelper::createMockUser('ivan');
        $authenticator = $this->buildAuthenticator(['ivan' => $user]);

        $accessToken = $authenticator->generateAccessToken($user);

        // Access tokens have no jti, so revocation should return false
        $result = $authenticator->revokeToken($accessToken);

        self::assertFalse($result);
    }

    /**
     * Build a testable JwtAuthenticator subclass that doesn't depend on the Grav locator.
     */
    private function buildAuthenticator(array $users): JwtAuthenticator
    {
        $accounts = TestHelper::createMockAccounts($users);
        $grav = TestHelper::createMockGrav(['accounts' => $accounts]);

        $config = TestHelper::createMockConfig([
            'plugins' => ['api' => ['auth' => [
                'jwt_secret' => self::SECRET,
                'jwt_algorithm' => self::ALGORITHM,
                'jwt_expiry' => 3600,
                'jwt_refresh_expiry' => 604800,
            ]]],
        ]);

        $tempDir = $this->tempDir;

        return new class ($grav, $config, $tempDir) extends JwtAuthenticator {
            public function __construct(
                Grav $grav,
                Config $config,
                private readonly string $dir,
            ) {
                parent::__construct($grav, $config);
            }

            protected function getSecret(): string
            {
                return $this->config->get('plugins.api.auth.jwt_secret');
            }

            protected function getRevokedTokensFile(): string
            {
                return $this->dir . '/revoked_tokens.json';
            }
        };
    }
}
