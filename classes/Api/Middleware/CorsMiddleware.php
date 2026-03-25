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

        $allowedOrigins = (array) $this->config->get('plugins.api.cors.origins', ['*']);

        if (in_array('*', $allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif (in_array($origin, $allowedOrigins, true)) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin');
        } else {
            return $response;
        }

        $credentials = $this->config->get('plugins.api.cors.credentials', false);
        if ($credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $exposeHeaders = (array) $this->config->get('plugins.api.cors.expose_headers', []);
        if ($exposeHeaders) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $exposeHeaders));
        }

        return $response;
    }

    public function createPreflightResponse(): ResponseInterface
    {
        $headers = [];

        $allowedOrigins = (array) $this->config->get('plugins.api.cors.origins', ['*']);
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

        if (in_array('*', $allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = '*';
        } elseif (in_array($origin, $allowedOrigins, true)) {
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
