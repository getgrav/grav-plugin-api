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

            // Require BOTH authenticated and authorized. A front-end login that has
            // passed its password but not yet its second factor leaves the session
            // user `authenticated` but `authorized === false`; accepting it here let
            // a password-only login reach the API with 2FA still outstanding
            // (GHSA-... — internal). `authorized` is the same flag the front end
            // treats as "fully logged in", and it is set true for a remember-me
            // session too (the auto-login path does not carry the `twofa` option, so
            // it is never AUTHORIZATION_DELAYED), so this does not regress remember
            // me — only the mid-2FA state is rejected. Per-route permission checks
            // (the user's `access` map, refreshed below) still gate what an accepted
            // user can actually do.
            if ($user && $user->exists() && $user->authenticated && $user->authorized) {
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
