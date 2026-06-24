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
        self::assertNotEmpty($decoded->jti, 'Access tokens must carry a jti so they can be revoked (GHSA-m8g9-wxhx-6f86)');
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
    public function revoked_access_token_no_longer_authenticates(): void
    {
        // GHSA-m8g9-wxhx-6f86: a generated access token now carries a jti, so it
        // can be revoked (e.g. on logout) and must stop authenticating at once
        // rather than living out its full lifetime.
        $user = TestHelper::createMockUser('ivan');
        $authenticator = $this->buildAuthenticator(['ivan' => $user]);

        $accessToken = $authenticator->generateAccessToken($user);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $accessToken],
        );
        self::assertNotNull($authenticator->authenticate($request), 'token should work before revocation');

        self::assertTrue($authenticator->revokeToken($accessToken), 'access token should be revocable');

        self::assertNull($authenticator->authenticate($request), 'revoked access token must not authenticate');
    }

    #[Test]
    public function legacy_access_token_without_jti_still_authenticates(): void
    {
        // Tokens minted before the jti was added have none. They must keep
        // working until they expire so upgrading the plugin doesn't log
        // everyone out. GHSA-m8g9-wxhx-6f86.
        $user = TestHelper::createMockUser('judy');
        $authenticator = $this->buildAuthenticator(['judy' => $user]);

        $token = JWT::encode([
            'iss' => 'grav-api',
            'sub' => 'judy',
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
            // no jti
        ], self::SECRET, self::ALGORITHM);

        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNotNull($authenticator->authenticate($request));
    }

    #[Test]
    public function rejects_access_token_for_disabled_account(): void
    {
        // The account-disable kill switch: validateToken reloads the user on
        // every request, so disabling an account stops its live access token
        // without waiting for expiry. GHSA-m8g9-wxhx-6f86.
        $user = TestHelper::createMockUser('mallory', ['state' => 'disabled']);
        $authenticator = $this->buildAuthenticator(['mallory' => $user]);

        $token = $authenticator->generateAccessToken($user);
        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function rejects_access_token_issued_before_user_cutoff(): void
    {
        // The per-user cutoff (bumped on password change / reset): any token
        // whose `iat` predates `api_tokens_valid_after` is rejected, killing
        // every outstanding token in one stroke. GHSA-m8g9-wxhx-6f86.
        $user = TestHelper::createMockUser('niaj', ['api_tokens_valid_after' => time() + 10]);
        $authenticator = $this->buildAuthenticator(['niaj' => $user]);

        $token = $authenticator->generateAccessToken($user); // iat = now, < cutoff
        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function accepts_access_token_issued_after_user_cutoff(): void
    {
        // A token minted after the cutoff (e.g. the fresh pair from logging back
        // in) must still authenticate. GHSA-m8g9-wxhx-6f86.
        $user = TestHelper::createMockUser('olivia', ['api_tokens_valid_after' => time() - 10]);
        $authenticator = $this->buildAuthenticator(['olivia' => $user]);

        $token = $authenticator->generateAccessToken($user); // iat = now, > cutoff
        $request = TestHelper::createMockRequest(
            headers: ['Authorization' => 'Bearer ' . $token],
        );

        self::assertNotNull($authenticator->authenticate($request));
    }

    #[Test]
    public function refresh_token_rejected_after_user_cutoff(): void
    {
        // The cutoff invalidates refresh tokens too, so a stolen refresh token
        // can't be traded for a new access token after a password reset.
        $user = TestHelper::createMockUser('peggy', ['api_tokens_valid_after' => time() + 10]);
        $authenticator = $this->buildAuthenticator(['peggy' => $user]);

        $refreshToken = $authenticator->generateRefreshToken($user);

        self::assertNull($authenticator->validateRefreshToken($refreshToken));
    }

    #[Test]
    public function token_query_param_ignored_on_json_route(): void
    {
        // GHSA-hqm9-5xxw-4qxp: a JWT in `?token=` must not authenticate ordinary
        // (non-file) routes, which keeps access tokens out of URLs/logs and
        // removes the cross-origin takeover primitive.
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = $this->accessToken('alice');

        $request = TestHelper::createMockRequest(
            path: '/api/v1/me',
            queryParams: ['token' => $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function token_query_param_ignored_on_state_changing_method(): void
    {
        // Even on an otherwise allowed path, a write method must never be
        // authenticated by a URL token.
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = $this->accessToken('alice');

        $request = TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/system/backups/site.zip/download',
            queryParams: ['token' => $token],
        );

        self::assertNull($authenticator->authenticate($request));
    }

    #[Test]
    public function token_query_param_allowed_on_backup_download(): void
    {
        // The legitimate use case: a GET to a file-streaming route that a
        // browser `<a download>` can reach but can't attach a header to.
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = $this->accessToken('alice');

        $request = TestHelper::createMockRequest(
            path: '/api/v1/system/backups/site.zip/download',
            queryParams: ['token' => $token],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    #[Test]
    public function token_query_param_allowed_on_thumbnail(): void
    {
        $user = TestHelper::createMockUser('alice');
        $authenticator = $this->buildAuthenticator(['alice' => $user]);

        $token = $this->accessToken('alice');

        $request = TestHelper::createMockRequest(
            path: '/api/v1/thumbnails/user/pages/01.home/photo.jpg',
            queryParams: ['token' => $token],
        );

        $result = $authenticator->authenticate($request);

        self::assertNotNull($result);
        self::assertSame('alice', $result->username);
    }

    private function accessToken(string $username): string
    {
        return JWT::encode([
            'iss' => 'grav-api',
            'sub' => $username,
            'iat' => time(),
            'exp' => time() + 3600,
            'type' => 'access',
        ], self::SECRET, self::ALGORITHM);
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
