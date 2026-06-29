<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Session;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Headless SSO / OAuth login bridge for the admin-next SPA.
 *
 * The classic admin login is a Twig page that auth plugins (login-oauth2, etc.)
 * decorate with provider buttons and drive through Grav form tasks + session
 * login. admin-next never renders that page — it authenticates over JSON and
 * holds a stateless JWT. This controller is the generic seam between the two:
 * it owns four public endpoints and delegates every provider-specific step to
 * whichever plugin answers the `onApiLogin*` events, so any SSO mechanism
 * (OAuth, SAML, OIDC, …) can light up the SPA login screen without the API
 * plugin knowing anything about the protocol.
 *
 * Flow:
 *   1. GET  /auth/sso/providers          → list buttons to render
 *   2. GET  /auth/sso/{provider}/start    → 302 to the provider (state in session)
 *   3. GET  /auth/sso/{provider}/callback → provider returns here; we mint a
 *                                           token pair (or 2FA challenge), stash
 *                                           it under a one-time code, and 302
 *                                           back into the SPA
 *   4. POST /auth/sso/exchange            → SPA trades the code for the same
 *                                           response body /auth/token returns
 *
 * All four live under the `/auth/` public prefix, so no token is required to
 * reach them.
 */
class SsoController extends AbstractApiController
{
    use ResolvesAdminBaseUrl;

    /** Session keys for the SPA return target, resolved at `start` time. */
    private const SESSION_BASE = 'sso_spa_base';
    private const SESSION_RETURN = 'sso_return_to';

    /** One-time exchange-code lifetime (seconds) and cache-key prefix. */
    private const EXCHANGE_TTL = 120;
    private const EXCHANGE_PREFIX = 'api-sso-exchange-';

    /**
     * GET /auth/sso/providers
     *
     * Aggregate the login providers every SSO plugin wants shown on the login
     * screen. Public — the list is non-sensitive (ids, labels, icons).
     */
    public function providers(ServerRequestInterface $request): ResponseInterface
    {
        $event = $this->fireEvent('onApiLoginProviders', ['providers' => []]);

        $providers = [];
        foreach ((array) ($event['providers'] ?? []) as $provider) {
            if (!is_array($provider) || empty($provider['id'])) {
                continue;
            }
            $providers[] = [
                'id'     => (string) $provider['id'],
                'label'  => (string) ($provider['label'] ?? $provider['id']),
                'icon'   => (string) ($provider['icon'] ?? ''),
                'plugin' => (string) ($provider['plugin'] ?? ''),
            ];
        }

        return ApiResponse::create(['providers' => $providers]);
    }

    /**
     * GET /auth/sso/{provider}/start
     *
     * Hand off to the provider plugin to build the authorization URL (and
     * persist its own CSRF state in the session), then 302 the browser there.
     */
    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $provider = (string) $this->getRouteParam($request, 'provider');
        $this->startSession();

        // Remember where to drop the user back into the SPA once the dance
        // completes. Resolved now (from this same-origin navigation) because by
        // callback time the Referer is the external provider, not the SPA.
        $spaBase = $this->resolveSpaBase($request);
        $returnTo = $this->sanitizeReturnTo((string) ($request->getQueryParams()['returnTo'] ?? ''));

        $session = $this->grav['session'];
        $session->{self::SESSION_BASE} = $spaBase;
        $session->{self::SESSION_RETURN} = $returnTo;

        $event = $this->fireEvent('onApiLoginStart', [
            'provider'     => $provider,
            'request'      => $request,
            'callback_url' => $this->callbackUrl($provider),
            'return_to'    => $returnTo,
            'redirect'     => null,
            'error'        => null,
        ]);

        $redirect = $event['redirect'] ?? null;
        if (is_string($redirect) && $redirect !== '') {
            return $this->redirect($redirect);
        }

        return $this->redirect($this->loginErrorUrl($spaBase, (string) ($event['error'] ?? 'sso_unavailable')));
    }

    /**
     * GET /auth/sso/{provider}/callback
     *
     * The provider redirects the browser here with its authorization result.
     * The provider plugin validates state + exchanges the code for a Grav user;
     * we then run the shared API-access / 2FA gate, stash the resulting payload
     * under a single-use code, and bounce back into the SPA.
     */
    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        $provider = (string) $this->getRouteParam($request, 'provider');
        $this->startSession();

        $session = $this->grav['session'];
        $spaBase = (string) ($session->{self::SESSION_BASE} ?? $this->serverSpaBase());
        $returnTo = (string) ($session->{self::SESSION_RETURN} ?? '');

        $event = $this->fireEvent('onApiLoginCallback', [
            'provider'     => $provider,
            'request'      => $request,
            'callback_url' => $this->callbackUrl($provider),
            'user'         => null,
            'error'        => null,
        ]);

        $user = $event['user'] ?? null;
        if (!$user instanceof UserInterface) {
            return $this->redirect($this->loginErrorUrl($spaBase, (string) ($event['error'] ?? 'sso_failed')));
        }

        try {
            // Same gate password login runs: API-access + account-state checks,
            // then a 2FA challenge or a full token pair.
            $payload = $this->finalizeAuthenticatedUser($user, $request);
        } catch (ForbiddenException) {
            return $this->redirect($this->loginErrorUrl($spaBase, 'sso_forbidden'));
        }

        if (($payload['requires_2fa'] ?? false) !== true) {
            $this->fireEvent('onApiUserLogin', [
                'user' => $user,
                'method' => 'sso',
                'provider' => $provider,
                'ip' => $this->getRequestIp($request),
                'request' => $request,
            ]);
        }

        $code = $this->stashPayload($payload);

        $target = rtrim($spaBase, '/') . '/oauth-callback?code=' . rawurlencode($code);
        if ($returnTo !== '') {
            $target .= '&returnTo=' . rawurlencode($returnTo);
        }

        // Clear the one-shot session crumbs now that they're encoded in the URL.
        unset($session->{self::SESSION_BASE}, $session->{self::SESSION_RETURN});

        return $this->redirect($target);
    }

    /**
     * POST /auth/sso/exchange
     *
     * Trade the one-time code for the stashed login payload. Returns the exact
     * same body /auth/token does — a token pair, or a `requires_2fa` challenge
     * the SPA feeds into its existing 2FA stage. Single use: the code is deleted
     * on first read.
     */
    public function exchange(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['code']);

        $payload = $this->popPayload((string) $body['code']);
        if ($payload === null) {
            throw new UnauthorizedException('Invalid or expired exchange code.');
        }

        return ApiResponse::create($payload);
    }

    // ─── Internals ───────────────────────────────────────────────────────────

    /**
     * Persist a login payload under a fresh single-use code. Cache-backed (not
     * session) so the SPA's exchange call works even when it lives on a
     * different origin and carries no session cookie.
     */
    private function stashPayload(array $payload): string
    {
        $code = bin2hex(random_bytes(32));
        $this->grav['cache']->save(self::EXCHANGE_PREFIX . $code, $payload, self::EXCHANGE_TTL);
        return $code;
    }

    /** @return array<string, mixed>|null */
    private function popPayload(string $code): ?array
    {
        if ($code === '' || !ctype_xdigit($code)) {
            return null;
        }
        $key = self::EXCHANGE_PREFIX . $code;
        $payload = $this->grav['cache']->fetch($key);
        $this->grav['cache']->delete($key);

        return is_array($payload) ? $payload : null;
    }

    private function redirect(string $url): ResponseInterface
    {
        return new Response(302, ['Location' => $url]);
    }

    /** Absolute URL of this controller's callback for a given provider. */
    private function callbackUrl(string $provider): string
    {
        $base = $this->config->get('plugins.api.route', '/api');
        $prefix = $this->config->get('plugins.api.version_prefix', 'v1');
        $apiBase = '/' . trim((string) $base, '/') . '/' . trim((string) $prefix, '/');
        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');

        return $root . $apiBase . '/auth/sso/' . rawurlencode($provider) . '/callback';
    }

    private function loginErrorUrl(string $spaBase, string $error): string
    {
        return rtrim($spaBase, '/') . '/login?sso_error=' . rawurlencode($error);
    }

    /**
     * Resolve the SPA's base URL (origin + admin route) from this navigation,
     * reusing the origin-allowlist vetting that guards reset/invite links. The
     * login page is the Referer, so strip the trailing `/login`.
     */
    private function resolveSpaBase(ServerRequestInterface $request): string
    {
        return $this->resolveAdminBaseUrl(null, $request, ['/login']);
    }

    /** Server-origin fallback: own root URL + the configured admin2 route. */
    private function serverSpaBase(): string
    {
        $root = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        $route = '/' . trim((string) $this->config->get('plugins.admin2.route', '/admin'), '/');

        return $root . $route;
    }

    private function startSession(): void
    {
        /** @var Session $session */
        $session = $this->grav['session'];
        if (!$session->isStarted()) {
            $session->init();
        }
    }

    /**
     * Only accept an in-app return path (`/...`), never an absolute URL — this
     * value is later round-tripped into a redirect, so an open-redirect here
     * would be a login-phishing vector.
     */
    private function sanitizeReturnTo(string $returnTo): string
    {
        if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            return '';
        }
        return $returnTo;
    }
}
