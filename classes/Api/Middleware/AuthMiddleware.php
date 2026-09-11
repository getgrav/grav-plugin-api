<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Auth\ApiKeyAuthenticator;
use Grav\Plugin\Api\Auth\AuthenticatorInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Auth\SessionAuthenticator;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Psr\Http\Message\ServerRequestInterface;

class AuthMiddleware
{
    /** @var AuthenticatorInterface[] */
    protected array $authenticators = [];

    public function __construct(
        protected readonly Grav $grav,
        protected readonly Config $config,
    ) {
        $this->buildAuthenticatorChain();
    }

    public function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // Try each authenticator in order
        foreach ($this->authenticators as $authenticator) {
            $user = $authenticator->authenticate($request);
            if ($user !== null) {
                return $this->attachUser($request, $authenticator, $user);
            }
        }

        throw new UnauthorizedException(
            'No valid authentication credentials provided. Use an API key, JWT token, or active session.'
        );
    }

    /**
     * Optimistic authentication for public routes: attach api_user when valid
     * credentials are supplied, continue as guest otherwise. Lets public
     * endpoints return richer, permission-filtered responses to logged-in
     * callers without requiring auth from anonymous ones.
     */
    public function processOptional(ServerRequestInterface $request): ServerRequestInterface
    {
        foreach ($this->authenticators as $authenticator) {
            $user = $authenticator->authenticate($request);
            if ($user !== null) {
                return $this->attachUser($request, $authenticator, $user);
            }
        }

        return $request;
    }

    /**
     * Stamp the authenticated user onto the request, plus the API-key scopes
     * when the credential was an API key. requirePermission() reads
     * `api_key_scopes` to cap a scoped key to exactly its declared permissions,
     * independent of the owning account's ACL (GHSA-x7hm). JWT/session
     * credentials carry no scopes, so the attribute is absent for them and they
     * retain full account access.
     */
    private function attachUser(
        ServerRequestInterface $request,
        AuthenticatorInterface $authenticator,
        \Grav\Common\User\Interfaces\UserInterface $user,
    ): ServerRequestInterface {
        $request = $request->withAttribute('api_user', $user);

        $scopes = [];
        if ($authenticator instanceof ApiKeyAuthenticator) {
            $scopes = $authenticator->getAuthenticatedScopes();
            $request = $request->withAttribute('api_key_scopes', $scopes);
        }

        if ($scopes === []) {
            $this->setActiveUser($user);
        }

        return $request;
    }

    /**
     * Make the caller Grav's current user, so core, themes and other plugins
     * reading `$grav['user']` see who is calling rather than a guest (or, since
     * /api shares the front-end cookie, whoever is logged in to the public site
     * in the same browser). Classic admin did the same for its requests. (#36)
     *
     * Only the container entry changes. The session is left alone, so a visitor
     * logged in to the front end keeps their own login.
     *
     * JWT and API-key accounts come straight from disk, and core's authorize()
     * refuses a user without `authenticated` and `authorized`, so both are set
     * here as a login would set them (a JWT access token is only issued once
     * 2FA has passed). The account file never stores either flag.
     *
     * A key with scopes is skipped on purpose: its owner's ACL is wider than the
     * key, and anything authorizing against `$grav['user']` bypasses the scope
     * cap. Core's XSS whitelist in Validation::checkSafety() would, for one,
     * exempt a narrowly scoped key minted on a super-admin account. To core such
     * a request stays a guest, as it always was.
     */
    private function setActiveUser(\Grav\Common\User\Interfaces\UserInterface $user): void
    {
        $user->set('authenticated', true);
        $user->set('authorized', true);

        // Login defines `user` as a service, and Pimple refuses to replace one
        // that has already been read, so remove it first.
        unset($this->grav['user']);
        $this->grav['user'] = $user;
    }

    protected function buildAuthenticatorChain(): void
    {
        // API Key is fastest to check - try first
        if ($this->config->get('plugins.api.auth.api_keys_enabled', true)) {
            $this->authenticators[] = new ApiKeyAuthenticator($this->grav);
        }

        // JWT is next
        if ($this->config->get('plugins.api.auth.jwt_enabled', true)) {
            $this->authenticators[] = new JwtAuthenticator($this->grav, $this->config);
        }

        // Session passthrough is last (requires existing session)
        if ($this->config->get('plugins.api.auth.session_enabled', true)) {
            $this->authenticators[] = new SessionAuthenticator($this->grav);
        }
    }
}
