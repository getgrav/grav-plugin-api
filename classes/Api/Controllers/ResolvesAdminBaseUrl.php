<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the admin-next frontend base URL (scheme + host + port + any base
 * path) for building self-referential links inside emails (password reset,
 * invitations, …). Shared by AuthController and InvitationsController.
 *
 * These links carry pre-auth secrets (reset/invite tokens), so the host must
 * never be taken from client input on trust. A candidate base URL is accepted
 * only when its origin (scheme + host + port) is one we already trust:
 *   - the server's own origin (system.custom_base_url, else Grav's root URL), or
 *   - an origin explicitly allowlisted in the API's `cors.origins` — the same
 *     list the browser already had to be granted for cross-origin calls.
 *
 * Resolution order (each candidate must pass the origin allowlist):
 *   1. Explicit admin_base_url from the request body — the admin-next client
 *      sends `window.location.origin + base`.
 *   2. Referer header — fallback when the body field is missing.
 *   3. Origin header + Grav base path.
 * Anything else falls back to the server's own root URL — always safe.
 *
 * This closes GHSA-5xc4-j99p-cp4m (pre-auth reset-token poisoning): an
 * attacker-supplied `admin_base_url`/Referer/Origin pointing at an external
 * host no longer redirects the reset link, because it isn't in the allowlist.
 */
trait ResolvesAdminBaseUrl
{
    /**
     * @param string[] $stripSuffixes path suffixes to trim off the Referer
     *                                 path (e.g. ['/forgot', '/invite']) so we
     *                                 land at the admin-next root.
     */
    protected function resolveAdminBaseUrl(
        mixed $clientBaseUrl,
        ServerRequestInterface $request,
        array $stripSuffixes = ['/forgot'],
    ): string {
        $serverUrl = rtrim((string) $this->grav['uri']->rootUrl(true), '/');
        $allowedOrigins = $this->allowedAdminOrigins();

        // 1. Explicit admin_base_url from the request body.
        if (is_string($clientBaseUrl) && $clientBaseUrl !== '') {
            $candidate = $this->sanitizeHttpUrl($clientBaseUrl);
            if ($candidate !== null && $this->originAllowed($candidate, $allowedOrigins)) {
                return $candidate;
            }
        }

        // 2. Referer header.
        $referer = $request->getHeaderLine('Referer');
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                $path = $parts['path'] ?? '';
                foreach ($stripSuffixes as $suffix) {
                    if ($suffix !== '' && str_ends_with($path, $suffix)) {
                        $path = substr($path, 0, -\strlen($suffix));
                        break;
                    }
                }
                $candidate = $this->sanitizeHttpUrl($origin . rtrim($path, '/'));
                if ($candidate !== null && $this->originAllowed($candidate, $allowedOrigins)) {
                    return $candidate;
                }
            }
        }

        // 3. Origin header + Grav base path.
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '') {
            $basePath = (string) $this->grav['uri']->rootUrl(false);
            $candidate = $this->sanitizeHttpUrl(rtrim($origin, '/') . $basePath);
            if ($candidate !== null && $this->originAllowed($candidate, $allowedOrigins)) {
                return $candidate;
            }
        }

        // Last resort: Grav's own root URL — never attacker-controlled.
        return $serverUrl;
    }

    /**
     * Origins (scheme://host[:port]) that may legitimately appear in a
     * self-referential admin link: the server's own origin plus any browser
     * origins explicitly allowlisted for CORS. A `*` wildcard is deliberately
     * NOT honored here — it is meaningful only for reflecting unauthenticated
     * CORS responses, never for trusting a host that receives a secret token.
     *
     * @return string[]
     */
    private function allowedAdminOrigins(): array
    {
        $origins = [];

        $serverOrigin = $this->normalizeOrigin((string) $this->grav['uri']->rootUrl(true));
        if ($serverOrigin !== null) {
            $origins[$serverOrigin] = true;
        }

        foreach ((array) $this->config->get('plugins.api.cors.origins', []) as $corsOrigin) {
            if (!is_string($corsOrigin) || $corsOrigin === '' || $corsOrigin === '*') {
                continue;
            }
            $normalized = $this->normalizeOrigin($corsOrigin);
            if ($normalized !== null) {
                $origins[$normalized] = true;
            }
        }

        return array_keys($origins);
    }

    /**
     * @param string[] $allowedOrigins
     */
    private function originAllowed(string $url, array $allowedOrigins): bool
    {
        $origin = $this->normalizeOrigin($url);

        return $origin !== null && in_array($origin, $allowedOrigins, true);
    }

    /**
     * Reduce a URL to its canonical origin (lowercased scheme + host, plus an
     * explicit port if present) for case-insensitive allowlist comparison.
     */
    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    protected function sanitizeHttpUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        return rtrim($url, '/');
    }
}
