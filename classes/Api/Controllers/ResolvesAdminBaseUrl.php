<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Resolves the admin-next frontend base URL (scheme + host + port + any base
 * path) for building self-referential links inside emails (password reset,
 * invitations, …). Shared by AuthController and InvitationsController.
 *
 * Resolution priority:
 *   1. Explicit admin_base_url from the request body — the admin-next client
 *      sends `window.location.origin + base`, always correct for browsers.
 *   2. Referer header — fallback when the body field is missing.
 *   3. Origin header + Grav base path — last resort.
 *
 * Any accepted value is sanity-checked: only http(s) URLs are allowed so a
 * link can't be coerced into producing something like a javascript: or
 * data: URL.
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
        if (is_string($clientBaseUrl) && $clientBaseUrl !== '') {
            $normalized = $this->sanitizeHttpUrl($clientBaseUrl);
            if ($normalized !== null) {
                return $normalized;
            }
        }

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
                $normalized = $this->sanitizeHttpUrl($origin . rtrim($path, '/'));
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '') {
            $basePath = (string) $this->grav['uri']->rootUrl(false);
            $normalized = $this->sanitizeHttpUrl(rtrim($origin, '/') . $basePath);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        // Last resort: Grav's own root URL. Wrong in dev when admin-next runs
        // on a separate origin, but at least a valid URL.
        return rtrim((string) $this->grav['uri']->rootUrl(true), '/');
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
