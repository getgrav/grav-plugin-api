<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Plugin\Api\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

class JsonBodyParserMiddleware
{
    public function processRequest(ServerRequestInterface $request): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (!str_contains($contentType, 'application/json')) {
            return $request;
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return $request->withAttribute('json_body', []);
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('Invalid JSON in request body: ' . json_last_error_msg());
        }

        return $request->withAttribute('json_body', $decoded);
    }
}
