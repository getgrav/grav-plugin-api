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
                return $request->withAttribute('api_user', $user);
            }
        }

        throw new UnauthorizedException(
            'No valid authentication credentials provided. Use an API key, JWT token, or active session.'
        );
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
