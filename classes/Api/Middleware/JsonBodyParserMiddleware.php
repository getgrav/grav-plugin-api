<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\Helpers\JsonBody;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use JsonException;
use LengthException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Puts the decoded JSON body of an API request in the `json_body` attribute.
 *
 * Grav 2.2.20+ decodes the body once, within `system.request.json` limits, before
 * routing: JsonBody::decode() hands back that same result here instead of parsing
 * the body again, and turns a body over the limits into a 413. Older cores have
 * already decoded an application/json body into the parsed body, which is reused.
 */
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

        if ($this->coreDecodesWithinLimits()) {
            try {
                $decoded = JsonBody::decode($body);
            } catch (LengthException $e) {
                throw new ApiException(413, 'Payload Too Large', $e->getMessage());
            } catch (JsonException $e) {
                throw new ValidationException('Invalid JSON in request body: ' . $e->getMessage());
            }

            return $request->withAttribute('json_body', $decoded);
        }

        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $request->withAttribute('json_body', $parsed);
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ValidationException('Invalid JSON in request body: ' . json_last_error_msg());
        }

        return $request->withAttribute('json_body', $decoded);
    }

    /**
     * Whether core has JsonBody (Grav 2.2.20+).
     */
    protected function coreDecodesWithinLimits(): bool
    {
        return method_exists(JsonBody::class, 'decode');
    }
}
