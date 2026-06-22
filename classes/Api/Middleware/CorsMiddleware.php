<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\Config\Config;
use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class CorsMiddleware
{
    public function __construct(
        protected readonly Config $config,
    ) {}

    public function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        // Nothing to modify on the request, CORS is response-side
        return $request;
    }

    public function addHeaders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->config->get('plugins.api.cors.enabled', true)) {
            return $response;
        }

        $origin = $request->getHeaderLine('Origin');
        if (!$origin) {
            return $response;
        }

        $allowedOrigins = (array) $this->config->get('plugins.api.cors.origins', []);

        if (in_array($origin, $allowedOrigins, true)) {
            // Explicitly allowlisted origin: safe to reflect, even for
            // authenticated responses. Vary so shared caches don't serve one
            // origin's response to another.
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin');
        } elseif (in_array('*', $allowedOrigins, true) && $request->getAttribute('api_user') === null) {
            // Wildcard is honored ONLY for unauthenticated (guest) responses,
            // e.g. /ping or /translations. Emitting `*` on an authenticated
            // response lets any website read it for any token the attacker can
            // supply (header or `?token=`), which is the cross-origin
            // account-takeover vector. Authenticated responses fall through to
            // "no CORS header" unless the origin is explicitly allowlisted
            // above. GHSA-hqm9-5xxw-4qxp.
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } else {
            return $response;
        }

        $credentials = $this->config->get('plugins.api.cors.credentials', false);
        if ($credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $exposeHeaders = (array) $this->config->get('plugins.api.cors.expose_headers', []);
        // Always expose X-Invalidates (cache invalidation tags) and ETag (optimistic
        // concurrency). Without ETag in the expose list a cross-origin client cannot
        // read it, so If-Match is never sent and concurrency protection silently lapses.
        foreach (['X-Invalidates', 'ETag'] as $always) {
            if (!in_array($always, $exposeHeaders, true)) {
                $exposeHeaders[] = $always;
            }
        }
        $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposeHeaders));

        return $response;
    }

    public function createPreflightResponse(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];

        $allowedOrigins = (array) $this->config->get('plugins.api.cors.origins', []);
        $origin = $request->getHeaderLine('Origin');

        // Preflight is required only for non-simple requests (custom headers,
        // JSON bodies, unsafe methods) — i.e. exactly the cross-origin requests
        // that can change state. We grant it only to explicitly allowlisted
        // origins, never to `*`: a wildcard preflight would let any site send an
        // authenticated POST/DELETE that the browser then executes. A simple
        // cross-origin GET to a public endpoint needs no preflight and is still
        // handled by addHeaders(). GHSA-hqm9-5xxw-4qxp.
        if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
            $headers['Vary'] = 'Origin';
        }

        $methods = (array) $this->config->get('plugins.api.cors.methods', ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS']);
        $headers['Access-Control-Allow-Methods'] = implode(', ', $methods);

        $allowHeaders = (array) $this->config->get('plugins.api.cors.headers', []);
        if ($allowHeaders) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $allowHeaders);
        }

        $maxAge = $this->config->get('plugins.api.cors.max_age', 86400);
        $headers['Access-Control-Max-Age'] = (string) $maxAge;

        $credentials = $this->config->get('plugins.api.cors.credentials', false);
        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $headers['Content-Length'] = '0';

        return new Response(204, $headers);
    }
}
