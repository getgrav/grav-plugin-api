<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Response;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * RFC 7807 Problem Details response builder.
 */
class ErrorResponse
{
    public static function create(int $status, string $title, string $detail, array $headers = []): ResponseInterface
    {
        $body = [
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
        ];

        $headers = array_merge($headers, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response($status, $headers, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    public static function fromException(ApiException $e): ResponseInterface
    {
        $body = [
            'status' => $e->getStatusCode(),
            'title' => $e->getErrorTitle(),
            'detail' => $e->getMessage(),
        ];

        if ($e instanceof ValidationException && $e->getValidationErrors()) {
            $body['errors'] = $e->getValidationErrors();
        }

        $headers = array_merge($e->getHeaders(), [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response($e->getStatusCode(), $headers, json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
