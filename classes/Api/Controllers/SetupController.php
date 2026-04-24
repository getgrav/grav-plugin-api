<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\TooManyRequestsException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\PasswordPolicyService;
use Grav\Plugin\Login\Login;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the one-time first-run setup for fresh Grav 2.0 installs that use
 * Admin-Next + API. Active only while user/accounts/ is empty; once any user
 * is created the endpoints 409.
 */
class SetupController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create([
            'setup_required' => $this->noAccountsExist(),
            'password_policy' => PasswordPolicyService::build($this->config),
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->enforceSetupRateLimit($request);

        if (!$this->noAccountsExist()) {
            throw new ConflictException('Setup has already been completed.');
        }

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password', 'email']);

        $username = (string) $body['username'];
        $password = (string) $body['password'];
        $email = (string) $body['email'];

        if (!preg_match('/^[a-z0-9_-]{3,64}$/i', $username)) {
            throw new ValidationException(
                'Invalid username format.',
                [['field' => 'username', 'message' => 'Username must be 3-64 characters and contain only letters, numbers, hyphens, and underscores.']],
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException(
                'Invalid email address.',
                [['field' => 'email', 'message' => 'A valid email address is required.']],
            );
        }

        $pwdRegex = (string) $this->config->get('system.pwd_regex', '');
        if ($pwdRegex !== '' && !@preg_match('#^(?:' . $pwdRegex . ')$#', $password)) {
            throw new ValidationException(
                'Password does not meet the required policy.',
                [['field' => 'password', 'message' => 'Password does not meet the required policy.']],
            );
        } elseif ($pwdRegex === '' && strlen($password) < 8) {
            throw new ValidationException(
                'Password is too short.',
                [['field' => 'password', 'message' => 'Password must be at least 8 characters.']],
            );
        }

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];

        // Second race-guard check after acquiring accounts: another concurrent
        // setup call may have completed between the first check and now.
        if ($accounts->count() > 0) {
            throw new ConflictException('Setup has already been completed.');
        }

        $user = $accounts->load($username);
        $user->set('email', $email);
        $user->set('fullname', $body['fullname'] ?? $username);
        $user->set('title', $body['title'] ?? 'Administrator');
        $user->set('state', 'enabled');
        $user->set('access', [
            'site' => ['login' => true],
            'api'  => ['super' => true],
        ]);
        $user->set('hashed_password', Authentication::create($password));
        $user->set('created', time());
        $user->set('modified', time());
        // Flex user-accounts storage may still hold cached state for this
        // username from a previous account (avatar, 2FA, content editor, …).
        // Zero them out so the new super-admin is genuinely fresh.
        $user->set('avatar', []);
        $user->set('twofa_enabled', false);
        $user->set('twofa_secret', '');

        $user->save();

        $this->fireEvent('onApiUserCreated', ['user' => $user]);
        $this->fireEvent('onApiSetupComplete', ['user' => $user]);

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        return $this->issueTokenPair($jwt, $user);
    }

    private function noAccountsExist(): bool
    {
        /** @var UserCollectionInterface|null $accounts */
        $accounts = $this->grav['accounts'] ?? null;
        return $accounts !== null && $accounts->count() === 0;
    }

    /**
     * Defense-in-depth: even though this endpoint is self-disabling once any
     * user exists, rate-limit by IP to blunt rapid brute-force probing during
     * the eligible window. Reuses the login plugin's rate limiter keyed by a
     * synthetic "__api_setup__:{ip}" string.
     */
    private function enforceSetupRateLimit(ServerRequestInterface $request): void
    {
        if (!class_exists(Login::class) || !isset($this->grav['login'])) {
            return;
        }

        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $key = '__api_setup__:' . $ip;

        /** @var Login $login */
        $login = $this->grav['login'];
        $interval = $login->checkLoginRateLimit($key);

        if ($interval > 0) {
            throw new TooManyRequestsException(
                sprintf('Too many setup attempts. Try again in %d minutes.', $interval),
                $interval * 60,
            );
        }
    }
}
