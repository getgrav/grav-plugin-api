<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\PermissionResolver;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\UserSerializer;
use Grav\Plugin\Login\Login;
use Grav\Plugin\Login\TwoFactorAuth\TwoFactorAuth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthController extends AbstractApiController
{
    private const CHALLENGE_2FA = '2fa_challenge';
    private const CHALLENGE_TTL = 300;

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password']);

        $username = (string) $body['username'];
        $password = (string) $body['password'];

        $this->enforceLoginRateLimit($username);

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        // Delegate to User::authenticate() so the core trait's plaintext-password
        // fallback fires (auto-hashes a yaml-declared `password:` field on first
        // successful login, then saves — same behavior admin-classic and the Login
        // plugin have always had).
        if (!$user->exists() || !$user->authenticate($password)) {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $username,
                'reason' => 'password',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new UnauthorizedException('Invalid username or password.');
        }

        // Verify user has API access (direct access check, not authorize())
        if (!$this->isSuperAdmin($user) && !$this->hasPermission($user, 'api.access')) {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $username,
                'reason' => 'no_api_access',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new ForbiddenException('API access is not enabled for this user.');
        }

        if ($user->get('state', 'enabled') === 'disabled') {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $username,
                'reason' => 'disabled',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new ForbiddenException('This user account is disabled.');
        }

        $jwt = new JwtAuthenticator($this->grav, $this->config);

        if ($this->userRequiresTwoFactor($user)) {
            // Password was valid — issue a challenge token. Do NOT reset the
            // rate limiter yet: the login only counts as successful after the
            // 2FA code verifies in /auth/2fa/verify.
            $challengeToken = $jwt->generateChallengeToken($user, self::CHALLENGE_2FA, self::CHALLENGE_TTL);

            return ApiResponse::create([
                'requires_2fa' => true,
                'challenge_token' => $challengeToken,
                'expires_in' => self::CHALLENGE_TTL,
                'token_type' => 'Challenge',
            ]);
        }

        $this->resetLoginRateLimit($username);

        $this->fireEvent('onApiUserLogin', [
            'user' => $user,
            'method' => 'password',
            'ip' => $this->getRequestIp($request),
            'request' => $request,
        ]);

        return $this->issueTokenPair($jwt, $user);
    }

    public function verify2fa(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['challenge_token', 'code']);

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $user = $jwt->validateChallengeToken($body['challenge_token'], self::CHALLENGE_2FA);

        if ($user === null) {
            throw new UnauthorizedException('Invalid or expired challenge token.');
        }

        $username = $user->username;

        $this->enforceLoginRateLimit($username);

        if ($user->get('state', 'enabled') === 'disabled') {
            throw new ForbiddenException('This user account is disabled.');
        }

        if (!class_exists(TwoFactorAuth::class)) {
            throw new ForbiddenException('2FA support is not available.');
        }

        $secret = (string) $user->get('twofa_secret');
        $code = (string) $body['code'];

        $twoFa = new TwoFactorAuth();
        if (!$secret || !$twoFa->verifyCode($secret, $code)) {
            $this->fireEvent('onApiUserLoginFailure', [
                'username' => $username,
                'reason' => '2fa',
                'ip' => $this->getRequestIp($request),
            ]);
            throw new UnauthorizedException('Invalid 2FA code.');
        }

        // Burn the challenge token so it cannot be replayed.
        $jwt->revokeToken($body['challenge_token']);
        $this->resetLoginRateLimit($username);

        $this->fireEvent('onApiUserLogin', [
            'user' => $user,
            'method' => '2fa',
            'ip' => $this->getRequestIp($request),
            'request' => $request,
        ]);

        return $this->issueTokenPair($jwt, $user);
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['refresh_token']);

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $user = $jwt->validateRefreshToken($body['refresh_token']);

        if ($user === null) {
            throw new UnauthorizedException('Invalid or expired refresh token.');
        }

        if ($user->get('state', 'enabled') === 'disabled') {
            throw new ForbiddenException('This user account is disabled.');
        }

        // Revoke the old refresh token (rotation)
        $jwt->revokeToken($body['refresh_token']);

        return $this->issueTokenPair($jwt, $user);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['refresh_token']);

        $jwt = new JwtAuthenticator($this->grav, $this->config);

        // Best-effort: decode to capture the subject for the logout event.
        $user = $jwt->validateRefreshToken($body['refresh_token']);
        $jwt->revokeToken($body['refresh_token']);

        if ($user !== null) {
            $this->fireEvent('onApiUserLogout', [
                'user' => $user,
                'ip' => $this->getRequestIp($request),
                'request' => $request,
            ]);
        }

        return ApiResponse::noContent();
    }

    /**
     * POST /auth/forgot-password
     *
     * Accepts { email } and sends a password reset email if the address
     * matches a user. Always returns a neutral success message to prevent
     * account enumeration. Rate limited per-username via the login plugin's
     * `pw_resets` bucket so enumeration + flood attacks share the login
     * plugin's limits.
     */
    public function forgotPassword(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['email']);

        $email = htmlspecialchars(strip_tags((string) $body['email']), ENT_QUOTES, 'UTF-8');

        $neutralResponse = ApiResponse::create([
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $neutralResponse;
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->find($email, ['email']);

        if (!$user || !$user->exists()) {
            return $neutralResponse;
        }

        if (!isset($this->grav['Email']) || empty($this->config->get('plugins.email.from'))) {
            $this->grav['log']->warning('api.auth: forgot-password skipped — email plugin not configured.');
            return $neutralResponse;
        }

        if (!class_exists(Login::class) || !isset($this->grav['login'])) {
            $this->grav['log']->warning('api.auth: forgot-password skipped — login plugin not available.');
            return $neutralResponse;
        }

        /** @var Login $login */
        $login = $this->grav['login'];
        $rateLimiter = $login->getRateLimiter('pw_resets');
        $userKey = (string) $user->username;
        $rateLimiter->registerRateLimitedAction($userKey);

        if ($rateLimiter->isRateLimited($userKey)) {
            throw new TooManyRequestsException(
                sprintf('Too many password reset requests. Try again in %d minutes.', $rateLimiter->getInterval()),
                $rateLimiter->getInterval() * 60,
            );
        }

        try {
            $randomBytes = random_bytes(16);
        } catch (\Exception) {
            $randomBytes = (string) mt_rand();
        }

        $token = md5(uniqid($randomBytes, true));
        $expire = time() + 86400; // 24 hours

        // Same storage format as the login plugin's Controller::taskForgot,
        // so the reset token is compatible with either admin or site flows.
        $user->set('reset', $token . '::' . $expire);
        $user->save();

        try {
            $this->sendAdminNextResetEmail($user, $token, $body['admin_base_url'] ?? null, $request);
        } catch (\Throwable $e) {
            $this->grav['log']->error('api.auth: failed to send reset email: ' . $e->getMessage());
            // Still return neutral success — do not leak mail infrastructure errors.
        }

        return $neutralResponse;
    }

    /**
     * Send the admin-next password reset email. Self-contained: builds the
     * admin-next reset URL (pointing at its own /reset route, not the Grav
     * frontend login plugin's /reset_password page) and renders via the
     * API plugin's own template so the reset loop never leaves the admin UI.
     */
    private function sendAdminNextResetEmail(
        UserInterface $user,
        string $token,
        mixed $clientBaseUrl,
        ServerRequestInterface $request,
    ): void {
        if (!isset($this->grav['Email'])) {
            throw new \RuntimeException('Email service not available.');
        }

        $adminBase = $this->resolveAdminBaseUrl($clientBaseUrl, $request);

        $resetLink = rtrim($adminBase, '/')
            . '/reset?user=' . rawurlencode((string) $user->username)
            . '&token=' . rawurlencode($token);

        $cfg = $this->grav['config'];
        $siteHost = (string) ($cfg->get('plugins.login.site_host') ?: ($this->grav['uri']->host() ?? ''));

        $context = [
            'reset_link' => $resetLink,
            'user'       => $user,
            'site_name'  => $cfg->get('site.title', 'Website'),
            'site_host'  => $siteHost,
            'author'     => $cfg->get('site.author.name', ''),
        ];

        $params = [
            'to'   => $user->email,
            'body' => [
                [
                    'content_type' => 'text/html',
                    'template'     => 'emails/api/reset-password.html.twig',
                    'body'         => '',
                ],
            ],
        ];

        /** @var \Grav\Plugin\Email\Email $email */
        $email = $this->grav['Email'];
        $message = $email->buildMessage($params, $context);
        $email->send($message);
    }

    /**
     * Resolve the admin-next frontend base URL (scheme + host + port + any
     * base path). Priority:
     *   1. Explicit admin_base_url from the request body — the admin-next
     *      client sends `window.location.origin + base`, which is always
     *      correct for browser sessions.
     *   2. Referer header — fallback when the body field is missing.
     *   3. Origin header + Grav base path — last resort.
     *
     * Any accepted value is sanity-checked: only http(s) URLs are allowed
     * so the reset email can't be coerced into producing something like a
     * javascript: or data: link.
     */
    private function resolveAdminBaseUrl(mixed $clientBaseUrl, ServerRequestInterface $request): string
    {
        if (is_string($clientBaseUrl) && $clientBaseUrl !== '') {
            $normalized = $this->sanitizeHttpUrl($clientBaseUrl);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                $path = $parts['path'] ?? '';
                // Strip the /forgot suffix so we land at the admin-next root,
                // not one level deep.
                if (str_ends_with($path, '/forgot')) {
                    $path = substr($path, 0, -\strlen('/forgot'));
                }
                $normalized = $this->sanitizeHttpUrl($origin . rtrim($path, '/'));
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '') {
            $basePath = (string) $this->grav['uri']->rootUrl(false);
            $normalized = $this->sanitizeHttpUrl(rtrim($origin, '/') . $basePath);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        // Last resort: Grav's own root URL. Wrong in dev when admin-next runs
        // on a separate origin, but at least a valid URL.
        return rtrim((string) $this->grav['uri']->rootUrl(true), '/');
    }

    private function sanitizeHttpUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        return rtrim($url, '/');
    }

    /**
     * POST /auth/reset-password
     *
     * Accepts { username, token, password } and completes the password reset.
     * All failures return a deliberately vague error so token probing cannot
     * distinguish "no such user" from "wrong token" from "expired token". IP
     * is rate-limited via the login plugin's standard login bucket to cap
     * token brute-forcing.
     */
    public function resetPassword(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'token', 'password']);

        $username = (string) $body['username'];
        $token = (string) $body['token'];
        $password = (string) $body['password'];

        $this->enforceLoginRateLimit($username);

        $invalidMessage = 'Invalid or expired reset link.';

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        if (!$user->exists()) {
            throw new ValidationException($invalidMessage);
        }

        $storedReset = (string) $user->get('reset', '');
        if (!str_contains($storedReset, '::')) {
            throw new ValidationException($invalidMessage);
        }

        [$goodToken, $expire] = explode('::', $storedReset, 2);

        if (!hash_equals($goodToken, $token) || time() > (int) $expire) {
            throw new ValidationException($invalidMessage);
        }

        // Match the login plugin's reset sequence exactly (Controller::taskReset).
        unset($user->hashed_password, $user->reset);
        $user->password = $password;
        $user->save();

        $this->resetLoginRateLimit($username);

        $this->fireEvent('onApiPasswordReset', [
            'user' => $user,
            'ip' => $this->getRequestIp($request),
        ]);

        return ApiResponse::create([
            'message' => 'Password reset successfully.',
        ]);
    }

    /**
     * GET /me — Return the authenticated user's profile and resolved permissions.
     */
    public function me(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');

        $user = $this->getUser($request);
        $isSuperAdmin = $this->isSuperAdmin($user);

        $resolver = $this->getPermissionResolver();
        $resolvedAccess = $resolver->resolvedMap($user, $isSuperAdmin);

        return ApiResponse::create([
            'username'    => $user->username,
            'fullname'    => $user->get('fullname'),
            'email'       => $user->get('email'),
            'avatar_url'  => UserSerializer::resolveAvatarUrl($user),
            'super_admin' => $isSuperAdmin,
            'access'      => $resolvedAccess,
            'content_editor' => $user->get('content_editor', ''),
            'grav_version' => GRAV_VERSION,
            'admin_version' => $this->getAdminPluginVersion(),
        ]);
    }

    private function getAdminPluginVersion(): ?string
    {
        foreach (['admin2', 'admin'] as $slug) {
            if (!$this->config->get("plugins.{$slug}.enabled", false)) {
                continue;
            }
            $blueprintFile = $this->grav['locator']->findResource("plugins://{$slug}/blueprints.yaml");
            if (!$blueprintFile || !file_exists($blueprintFile)) {
                continue;
            }
            $data = \Grav\Common\Yaml::parse(file_get_contents($blueprintFile));
            $version = $data['version'] ?? null;
            if ($version) {
                return (string) $version;
            }
        }
        return null;
    }

    private function userRequiresTwoFactor(UserInterface $user): bool
    {
        if (!class_exists(TwoFactorAuth::class)) {
            return false;
        }

        if (!$this->config->get('plugins.login.twofa_enabled', false)) {
            return false;
        }

        return (bool) $user->get('twofa_enabled') && (bool) $user->get('twofa_secret');
    }

    /**
     * Call the login plugin's checkLoginRateLimit() which both registers and
     * checks attempts against max_login_count / max_login_interval using the
     * same cache store the frontend login uses. Throws 429 if the caller is
     * currently locked out.
     */
    private function enforceLoginRateLimit(string $username): void
    {
        if (!class_exists(Login::class) || !isset($this->grav['login'])) {
            return;
        }

        /** @var Login $login */
        $login = $this->grav['login'];
        $interval = $login->checkLoginRateLimit($username);

        if ($interval > 0) {
            throw new TooManyRequestsException(
                sprintf('Too many login attempts. Try again in %d minutes.', $interval),
                $interval * 60,
            );
        }
    }

    private function resetLoginRateLimit(string $username): void
    {
        if (!class_exists(Login::class) || !isset($this->grav['login'])) {
            return;
        }
        /** @var Login $login */
        $login = $this->grav['login'];
        $login->resetLoginRateLimit($username);
    }

    private function getRequestIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();
        return (string) ($server['REMOTE_ADDR'] ?? '');
    }
}
