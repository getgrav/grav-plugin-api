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
                        if (!$fresh->exists() || $fresh->get('state', 'enabled') !== 'enabled') {
                            return null;
                        }

                        // A session that signed in before the account's password
                        // last changed is refused, like a JWT issued before then.
                        // The Login plugin signs such a session out on its own;
                        // this keeps the API closed with an older Login plugin.
                        $cutoff = (int) $fresh->get('api_tokens_valid_after', 0);
                        if ($cutoff > 0 && (int) $user->get('api_tokens_valid_after', 0) < $cutoff) {
                            return null;
                        }

                        $user->set('state', $fresh->get('state', 'enabled'));
                        $user->set('access', $fresh->get('access'));
                        $user->set('groups', $fresh->get('groups'));
                    } catch (Throwable) {
                        // Revocation and permission checks must fail closed. A
                        // stale serialized session is not sufficient authority
                        // when the account cannot be refreshed.
                        return null;
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
