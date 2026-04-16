<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class JwtAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        protected readonly Grav $grav,
        protected readonly Config $config,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?UserInterface
    {
        $token = $this->extractBearerToken($request);
        if (!$token) {
            return null;
        }

        return $this->validateToken($token);
    }

    /**
     * Generate an access token for a user.
     */
    public function generateAccessToken(UserInterface $user): string
    {
        $secret = $this->getSecret();
        $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');
        $expiry = (int) $this->config->get('plugins.api.auth.jwt_expiry', 3600);

        $payload = [
            'iss' => 'grav-api',
            'sub' => $user->username,
            'iat' => time(),
            'exp' => time() + $expiry,
            'type' => 'access',
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Generate a refresh token for a user.
     */
    public function generateRefreshToken(UserInterface $user): string
    {
        $secret = $this->getSecret();
        $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');
        $expiry = (int) $this->config->get('plugins.api.auth.jwt_refresh_expiry', 604800);

        $payload = [
            'iss' => 'grav-api',
            'sub' => $user->username,
            'iat' => time(),
            'exp' => time() + $expiry,
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Generate a short-lived, single-use challenge token for flows like 2FA
     * verification or password reset handoff. The $purpose field is stored in
     * the token's `type` claim and must match on validation.
     */
    public function generateChallengeToken(UserInterface $user, string $purpose, int $ttl = 300): string
    {
        $secret = $this->getSecret();
        $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');

        $payload = [
            'iss' => 'grav-api',
            'sub' => $user->username,
            'iat' => time(),
            'exp' => time() + $ttl,
            'type' => $purpose,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $secret, $algorithm);
    }

    /**
     * Validate a challenge token and return the associated user. The token must
     * carry the expected purpose in its `type` claim and must not have been
     * revoked. Returns null if invalid, expired, or revoked.
     */
    public function validateChallengeToken(string $token, string $expectedPurpose): ?UserInterface
    {
        try {
            $secret = $this->getSecret();
            $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));

            if (($decoded->type ?? null) !== $expectedPurpose) {
                return null;
            }

            if ($this->isTokenRevoked($decoded->jti ?? '')) {
                return null;
            }

            /** @var UserCollectionInterface $accounts */
            $accounts = $this->grav['accounts'];
            $user = $accounts->load($decoded->sub);

            return $user->exists() ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Validate a refresh token and return the associated user.
     */
    public function validateRefreshToken(string $token): ?UserInterface
    {
        try {
            $secret = $this->getSecret();
            $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));

            if (($decoded->type ?? null) !== 'refresh') {
                return null;
            }

            // Check if token has been revoked
            if ($this->isTokenRevoked($decoded->jti ?? '')) {
                return null;
            }

            /** @var UserCollectionInterface $accounts */
            $accounts = $this->grav['accounts'];
            $user = $accounts->load($decoded->sub);

            return $user->exists() ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Revoke a refresh token by its JTI.
     */
    public function revokeToken(string $token): bool
    {
        try {
            $secret = $this->getSecret();
            $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));
            $jti = $decoded->jti ?? null;

            if (!$jti) {
                return false;
            }

            $this->addRevokedToken($jti, $decoded->exp ?? time() + 604800);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback: check query parameter for direct links (e.g. file downloads)
        $params = $request->getQueryParams();
        if (!empty($params['token'])) {
            return $params['token'];
        }

        return null;
    }

    protected function validateToken(string $token): ?UserInterface
    {
        try {
            $secret = $this->getSecret();
            $algorithm = $this->config->get('plugins.api.auth.jwt_algorithm', 'HS256');

            $decoded = JWT::decode($token, new Key($secret, $algorithm));

            // Only accept access tokens for API authentication
            if (($decoded->type ?? null) !== 'access') {
                return null;
            }

            /** @var UserCollectionInterface $accounts */
            $accounts = $this->grav['accounts'];
            $user = $accounts->load($decoded->sub);

            return $user->exists() ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getSecret(): string
    {
        $secret = $this->config->get('plugins.api.auth.jwt_secret', '');

        // Auto-generate secret if not set
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            $this->config->set('plugins.api.auth.jwt_secret', $secret);

            // Persist the generated secret so subsequent requests can verify
            // tokens signed with it. Without persistence every request re-mints
            // a different secret, producing the classic "login succeeds, next
            // request 401" reauth loop on a fresh install.
            //
            // findResource() with defaults (absolute=true, all=false) returns
            // either the first existing path or false — the previous third
            // `true` flag returned an array and silently broke the fallback.
            $locator = $this->grav['locator'];
            $file = $locator->findResource('config://plugins/api.yaml');
            if (!$file) {
                $configDir = $locator->findResource('config://', true);
                if (!$configDir) {
                    if (isset($this->grav['log'])) {
                        $this->grav['log']->warning('api.auth: could not resolve config:// stream to persist JWT secret; tokens will be single-request only until jwt_secret is configured.');
                    }
                    return $secret;
                }
                $file = $configDir . '/plugins/api.yaml';
            }

            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                if (isset($this->grav['log'])) {
                    $this->grav['log']->warning(sprintf('api.auth: could not create %s to persist JWT secret.', $dir));
                }
                return $secret;
            }

            $yaml = \Grav\Common\Yaml::parse(file_exists($file) ? file_get_contents($file) : '') ?? [];
            $yaml['auth']['jwt_secret'] = $secret;
            if (@file_put_contents($file, \Grav\Common\Yaml::dump($yaml)) === false) {
                if (isset($this->grav['log'])) {
                    $this->grav['log']->warning(sprintf('api.auth: could not write JWT secret to %s — tokens will not survive past this request.', $file));
                }
            }
        }

        return $secret;
    }

    protected function isTokenRevoked(string $jti): bool
    {
        $file = $this->getRevokedTokensFile();
        if (!file_exists($file)) {
            return false;
        }

        $revoked = json_decode(file_get_contents($file), true) ?: [];
        $this->cleanExpiredRevocations($revoked, $file);

        return isset($revoked[$jti]);
    }

    protected function addRevokedToken(string $jti, int $expiresAt): void
    {
        $file = $this->getRevokedTokensFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $revoked = [];
        if (file_exists($file)) {
            $revoked = json_decode(file_get_contents($file), true) ?: [];
        }

        $revoked[$jti] = $expiresAt;
        $this->cleanExpiredRevocations($revoked, $file);
    }

    protected function cleanExpiredRevocations(array &$revoked, string $file): void
    {
        $now = time();
        $revoked = array_filter($revoked, fn($exp) => $exp > $now);
        file_put_contents($file, json_encode($revoked));
    }

    protected function getRevokedTokensFile(): string
    {
        $locator = $this->grav['locator'];
        return $locator->findResource('cache://api', true, true) . '/revoked_tokens.json';
    }
}
