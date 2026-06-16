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

            // Accept any authenticated session user, including one restored via the
            // login plugin's "remember me" cookie (which leaves the user
            // `authenticated` but not `authorized`). Without this, a remembered user
            // shows as signed in in the UI yet every write call is rejected until a
            // fresh login. Per-route permission checks (the user's `access` map,
            // refreshed below) still gate what they can actually do, and the
            // remember-me cookie is itself HttpOnly/Secure/SameSite.
            if ($user && $user->exists() && $user->authenticated) {
                // Session stores a serialized user snapshot whose `access` map
                // is frozen at the moment of login. Admin permission changes
                // wouldn't take effect until the session is destroyed. Refresh
                // `access` from disk so an operator's grant/revoke is honored
                // on the next API request without forcing a re-login.
                $username = (string) $user->get('username');
                if ($username !== '') {
                    try {
                        $fresh = $this->grav['accounts']->load($username);
                        if ($fresh->exists()) {
                            $user->set('access', $fresh->get('access'));
                            $user->set('groups', $fresh->get('groups'));
                        }
                    } catch (Throwable) {
                        // Disk reload failed — fall through with stale access
                        // rather than denying a legitimately authenticated user.
                    }
                }
                return $user;
            }
        } catch (Throwable) {
            // Session not available or errored
        }

        return null;
    }
}
