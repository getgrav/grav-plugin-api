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
use RocketTheme\Toolbox\File\YamlFile;
use Throwable;

class JwtAuthenticator implements AuthenticatorInterface
{
    /**
     * Path segments/suffixes on which the `?token=` URL fallback is honored.
     * These are the only routes that stream a file body to a browser element
     * that can't attach an auth header. See {@see isTokenQueryAllowed()}.
     */
    protected const TOKEN_QUERY_ROUTES = [
        '/download',     // e.g. /system/backups/{filename}/download
        '/thumbnails',   // e.g. /thumbnails/{file}
    ];

    /** @var string|null in-process cache for the JWT signing secret */
    protected ?string $secret = null;

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
            // Like refresh tokens, access tokens carry a unique id so a single
            // token can be killed via the revocation list (e.g. on logout) and
            // so validateToken() can reject it before its natural expiry.
            // GHSA-m8g9-wxhx-6f86.
            'jti' => bin2hex(random_bytes(16)),
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

            // Same per-user kill switch as access tokens: a password change or
            // account disable invalidates outstanding refresh tokens too, so a
            // stolen refresh token can't be traded in for a fresh access token.
            if (!$user->exists() || !$this->userTokenStillValid($user, $decoded)) {
                return null;
            }

            return $user;
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

    /**
     * Public accessor for the bearer token attached to a request, used by the
     * logout flow to revoke the caller's current access token alongside its
     * refresh token. Returns null when no usable token is present.
     */
    public function extractRequestToken(ServerRequestInterface $request): ?string
    {
        return $this->extractBearerToken($request);
    }

    protected function extractBearerToken(ServerRequestInterface $request): ?string
    {
        // Primary: `X-API-Token` custom header. Preferred because it survives
        // FPM / FastCGI / CGI setups that silently strip the `Authorization`
        // header (MAMP's mod_fastcgi being the common trigger). Accepts either
        // a bare JWT or the traditional `Bearer <jwt>` form.
        $custom = trim($request->getHeaderLine('X-API-Token'));
        if ($custom !== '') {
            return str_starts_with($custom, 'Bearer ') ? substr($custom, 7) : $custom;
        }

        // Legacy / standards-compliant: `Authorization: Bearer <jwt>`.
        // Kept for external clients (curl, Postman, CI) and backward compat.
        $header = $request->getHeaderLine('Authorization');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback: query parameter for direct links (e.g. file downloads
        // where a browser `<a download>` / `<img src>` can't attach a header).
        //
        // A token in the URL leaks into access logs, the `Referer` header, and
        // browser history, so we accept it only where a browser genuinely can't
        // send a header: safe (GET/HEAD) requests to the handful of routes that
        // actually stream a file. Every state-changing method and every JSON
        // route ignores `?token=`, which keeps the access token out of URLs for
        // the API surface that matters (`/me`, `/users`, `/config`, `/pages`, …)
        // and removes the cross-origin account-takeover primitive. GHSA-hqm9-5xxw-4qxp.
        $params = $request->getQueryParams();
        if (!empty($params['token']) && $this->isTokenQueryAllowed($request)) {
            return $params['token'];
        }

        return null;
    }

    /**
     * Whether the `?token=` URL fallback may authenticate this request.
     *
     * Restricted to safe methods on file-streaming routes (backup downloads and
     * thumbnails) — the only places a browser element can't attach an auth
     * header. The match is on the request path suffix so it is independent of
     * the install's base path and configured API route/version prefix.
     */
    protected function isTokenQueryAllowed(ServerRequestInterface $request): bool
    {
        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            return false;
        }

        $path = rtrim($request->getUri()->getPath(), '/');
        foreach (self::TOKEN_QUERY_ROUTES as $needle) {
            if (str_ends_with($path, $needle) || str_contains($path . '/', $needle . '/')) {
                return true;
            }
        }

        return false;
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

            // A logged-out (or otherwise revoked) access token is dead even
            // before it expires. Tokens issued before this plugin added a `jti`
            // have none, so `?? ''` simply means "nothing to revoke" and they
            // keep working until they expire — the upgrade doesn't sign anyone
            // out. GHSA-m8g9-wxhx-6f86.
            if ($this->isTokenRevoked($decoded->jti ?? '')) {
                return null;
            }

            /** @var UserCollectionInterface $accounts */
            $accounts = $this->grav['accounts'];
            $user = $accounts->load($decoded->sub);

            if (!$user->exists() || !$this->userTokenStillValid($user, $decoded)) {
                return null;
            }

            return $user;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether a decoded token still passes the per-user gates that live on the
     * account rather than on the token: the account must not be disabled, and
     * the token must have been issued after the user's invalidation cutoff.
     *
     * The cutoff (`api_tokens_valid_after`) is a single timestamp bumped on
     * password change, account disable, or any "log out everywhere" action;
     * every token minted before it is rejected at once, which is the kill
     * switch a bearer JWT otherwise lacks. GHSA-m8g9-wxhx-6f86.
     */
    protected function userTokenStillValid(UserInterface $user, object $decoded): bool
    {
        if ($user->get('state', 'enabled') === 'disabled') {
            return false;
        }

        $cutoff = (int) $user->get('api_tokens_valid_after', 0);
        if ($cutoff > 0 && (int) ($decoded->iat ?? 0) < $cutoff) {
            return false;
        }

        return true;
    }

    /**
     * Per-site HMAC secret used to sign and verify every API JWT (access,
     * refresh, and challenge tokens). Backed by a local PHP file outside the
     * Config tree, so sandboxed Twig cannot read it via
     * `grav.config.get('plugins.api.auth.jwt_secret')` or `Config::toArray()`.
     * This mirrors how Grav core stores the security salt in
     * `security-private.php` (GHSA-3f29-pqwf-v4j4). Storing it as executable PHP
     * (rather than YAML) also means the secret is never emitted as plaintext if
     * the file is ever served directly.
     *
     * Migration: if the legacy `auth.jwt_secret` key is present in the loaded
     * Config (i.e. from an older install's `user/config/plugins/api.yaml`), its
     * value is copied into the private file on first call and scrubbed from both
     * the live Config and the on-disk YAML (GitHub #4150). Existing tokens keep
     * validating because the secret value is preserved.
     *
     * To rotate the secret manually, delete `user/config/plugins/api-private.php`;
     * the next request generates a fresh value, invalidating all outstanding
     * access and refresh tokens.
     */
    protected function getSecret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        $locator = $this->grav['locator'];
        $configFolder = $locator->findResource('config://', true) ?: $locator->findResource('config://', true, true);
        $privateFile = "{$configFolder}/plugins/api-private.php";

        if (is_file($privateFile)) {
            $value = @include $privateFile;
            if (is_string($value) && $value !== '') {
                return $this->secret = $value;
            }
            // Corrupt/empty file — fall through to regenerate.
        }

        // One-time migration out of Config for installs that persisted the
        // secret into plugins/api.yaml (GitHub #4150). Preserving the value
        // keeps every previously issued token valid across the upgrade.
        $legacy = $this->config->get('plugins.api.auth.jwt_secret');
        if (is_string($legacy) && $legacy !== '') {
            if ($this->writeSecret($privateFile, $legacy)) {
                $this->config->set('plugins.api.auth.jwt_secret', null);

                $apiYaml = "{$configFolder}/plugins/api.yaml";
                if (is_file($apiYaml)) {
                    $file = YamlFile::instance($apiYaml);
                    $content = (array) $file->content();
                    if (isset($content['auth']) && is_array($content['auth'])
                        && array_key_exists('jwt_secret', $content['auth'])) {
                        unset($content['auth']['jwt_secret']);
                        if ($content['auth'] === []) {
                            unset($content['auth']);
                        }
                        $file->content($content);
                        $file->save();
                    }
                    $file->free();
                }
            }

            return $this->secret = $legacy;
        }

        // Fresh install: generate and persist a new secret so subsequent
        // requests can verify tokens signed with it. Without persistence every
        // request re-mints a different secret, producing the classic "login
        // succeeds, next request 401" reauth loop.
        $generated = bin2hex(random_bytes(32));
        if (!$this->writeSecret($privateFile, $generated) && isset($this->grav['log'])) {
            $this->grav['log']->warning(sprintf(
                'api.auth: could not persist JWT secret to %s — tokens will be valid for this request only until the file is writable.',
                $privateFile
            ));
        }

        return $this->secret = $generated;
    }

    /**
     * Atomically write the signing secret to a PHP file that `return`s it as a
     * string. Mirrors Grav core's Security::writeNonceKey(). Returns false on
     * any I/O failure so the caller can degrade to a per-request secret rather
     * than failing the login outright.
     */
    protected function writeSecret(string $path, string $value): bool
    {
        $escaped = var_export($value, true);
        $contents = "<?php\n\n// Auto-generated private secret. Do NOT commit to version control.\n// Used to sign and verify API JWTs. Regenerate by deleting this file; the\n// next request will write a new value (invalidating all existing tokens).\n\nreturn {$escaped};\n";

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // Atomic write: stage to a temp file, then rename into place.
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        return true;
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
