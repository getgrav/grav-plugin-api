<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;

interface AuthenticatorInterface
{
    /**
     * Attempt to authenticate the request.
     * Returns the authenticated user, or null if this authenticator cannot handle the request.
     */
    public function authenticate(ServerRequestInterface $request): ?UserInterface;
}
