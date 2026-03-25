<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Auth;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

class SessionAuthenticator implements AuthenticatorInterface
{
    public function __construct(
        protected readonly Grav $grav,
    ) {}

    public function authenticate(ServerRequestInterface $request): ?UserInterface
    {
        try {
            /** @var \Grav\Common\Session $session */
            $session = $this->grav['session'];

            // Only if session is already started (e.g., from admin browsing)
            if (!$session->isStarted()) {
                return null;
            }

            /** @var UserInterface|null $user */
            $user = $session->user ?? null;

            if ($user && $user->exists() && $user->authorized) {
                return $user;
            }
        } catch (Throwable) {
            // Session not available or errored
        }

        return null;
    }
}
