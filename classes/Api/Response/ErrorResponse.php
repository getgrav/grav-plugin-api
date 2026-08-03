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
    /**
     * Encode a problem+json body without ever handing a `false` to the body.
     *
     * Same failure as ApiResponse::json(): json_encode() returns false on
     * malformed UTF-8 and the PSR-7 stream type-hints a string, so the false
     * came back out as a TypeError. It matters more here, because this is the
     * path that renders exceptions: an exception message carrying a bad byte
     * (a filename, a chunk of file content) would take out the error handler
     * itself, replacing a clean 4xx with an unhandled fatal.
     *
     * The original status is preserved rather than downgraded to 500 -- the
     * status is the part a client actually acts on and it is already known
     * good, so only the body degrades.
     *
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $body
     */
    private static function json(int $status, array $headers, array $body): ResponseInterface
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            $json = json_encode([
                'status' => $status,
                'title' => $body['title'] ?? 'Error',
                'detail' => 'The error detail could not be encoded as JSON: ' . json_last_error_msg(),
            ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
                ?: '{"status":' . $status . ',"title":"Error","detail":"The error could not be encoded as JSON."}';
        }

        return new Response($status, $headers, $json);
    }

    /**
     * @param array<string,mixed>      $headers
     * @param array<string,mixed>|null $toast  Optional toast hint honored by Admin
     *   Next: { message?, type?, duration?, dismissible? }. `duration` is in ms;
     *   use 0 (or dismissible:true) for a toast that stays until manually closed.
     */
    public static function create(int $status, string $title, string $detail, array $headers = [], ?array $toast = null): ResponseInterface
    {
        $body = [
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
        ];
        if ($toast !== null) {
            $body['toast'] = $toast;
        }

        $headers = array_merge($headers, [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return self::json($status, $headers, $body);
    }

    public static function fromException(ApiException $e): ResponseInterface
    {
        $body = [
            'status' => $e->getStatusCode(),
            'title' => $e->getErrorTitle(),
            'detail' => $e->getMessage(),
        ];

        if ($e->getErrorCode() !== null) {
            $body['code'] = $e->getErrorCode();
        }

        if ($e instanceof ValidationException && $e->getValidationErrors()) {
            $body['errors'] = $e->getValidationErrors();
        }

        $headers = array_merge($e->getHeaders(), [
            'Content-Type' => 'application/problem+json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return self::json($e->getStatusCode(), $headers, $body);
    }
}
