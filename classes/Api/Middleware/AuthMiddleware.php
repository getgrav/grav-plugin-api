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

        if ($authenticator instanceof ApiKeyAuthenticator) {
            $request = $request->withAttribute('api_key_scopes', $authenticator->getAuthenticatedScopes());
        }

        return $request;
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
