<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Transparent POST → {DELETE,PATCH,PUT} rewrite for clients behind restrictive
 * reverse proxies that reject non-standard HTTP verbs.
 *
 * Some managed nginx configurations (notably shared-hosting providers) strip
 * or 405 DELETE/PATCH before the request reaches PHP. This middleware lets the
 * admin-next client keep using semantic methods internally but fall back to
 * `POST + X-HTTP-Method-Override: <METHOD>` when it detects a proxy block. The
 * header is only honored on POST (other methods pass through untouched), and
 * only for the safelisted mutation verbs — no route should ever see an
 * "overridden GET", which would sidestep CSRF-shaped assumptions baked into
 * the routing layer.
 */
class MethodOverrideMiddleware
{
    private const ALLOWED_OVERRIDES = ['DELETE', 'PATCH', 'PUT'];

    public function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $request;
        }

        $override = strtoupper(trim($request->getHeaderLine('X-HTTP-Method-Override')));
        if ($override === '' || !in_array($override, self::ALLOWED_OVERRIDES, true)) {
            return $request;
        }

        return $request->withMethod($override);
    }
}
