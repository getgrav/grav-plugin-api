<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Response;

use Grav\Common\Grav;
use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class ApiResponse
{
    /** Encoding flags shared by every response body this class produces. */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Build a PSR-7 JSON response, without ever handing a `false` to the body.
     *
     * json_encode() returns false on malformed UTF-8, and Nyholm's
     * Stream::create() type-hints string|resource|StreamInterface, so that
     * false came back out as an unhandled TypeError instead of a response.
     * The trigger in practice was the system log viewer: grav.log accumulates
     * whatever gets logged, invalid byte sequences included, so the endpoint
     * that reads the log could not encode its own output.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE replaces bad bytes with U+FFFD rather than
     * failing. The guard below covers the remaining ways encoding can fail
     * (recursion depth, INF/NAN), where the honest answer is a 500 with a real
     * message instead of a half-serialized body under the original status.
     */
    private static function json(int $status, array $headers, array $body): ResponseInterface
    {
        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        $json = json_encode($body, self::JSON_FLAGS);

        if ($json === false) {
            $reason = json_last_error_msg();

            try {
                Grav::instance()['log']->error('API: response body could not be JSON-encoded: ' . $reason);
            } catch (\Throwable) {
                // Logging must never be the reason a response fails to render.
            }

            $status = 500;
            $json = json_encode([
                'error' => [
                    'code' => 'response_encoding_failed',
                    'message' => 'The response could not be encoded as JSON: ' . $reason,
                ],
            ], self::JSON_FLAGS) ?: '{"error":{"code":"response_encoding_failed"}}';
        }

        return new Response($status, $headers, $json);
    }

    /**
     * Create a standard JSON response with the data envelope.
     */
    public static function create(mixed $data, int $status = 200, array $headers = [], ?array $meta = null): ResponseInterface
    {
        $body = [
            'data' => $data,
        ];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return self::json($status, $headers, $body);
    }

    /**
     * Create a paginated response with meta and links.
     */
    public static function paginated(
        array $data,
        int $total,
        int $page,
        int $perPage,
        string $baseUrl,
        int $status = 200,
        array $headers = [],
        array $extraMeta = [],
        ?int $locatedAtIndex = null,
    ): ResponseInterface {
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
        if ($locatedAtIndex !== null) {
            $pagination['located_at_index'] = $locatedAtIndex;
        }

        $meta = [
            'pagination' => $pagination,
        ];

        if ($extraMeta !== []) {
            $meta = array_merge($meta, $extraMeta);
        }

        $body = [
            'data' => $data,
            'meta' => $meta,
            'links' => [
                'self' => $baseUrl . '?' . http_build_query(['page' => $page, 'per_page' => $perPage]),
            ],
        ];

        if ($page > 1) {
            $body['links']['first'] = $baseUrl . '?' . http_build_query(['page' => 1, 'per_page' => $perPage]);
            $body['links']['prev'] = $baseUrl . '?' . http_build_query(['page' => $page - 1, 'per_page' => $perPage]);
        }

        if ($page < $totalPages) {
            $body['links']['next'] = $baseUrl . '?' . http_build_query(['page' => $page + 1, 'per_page' => $perPage]);
            $body['links']['last'] = $baseUrl . '?' . http_build_query(['page' => $totalPages, 'per_page' => $perPage]);
        }

        return self::json($status, $headers, $body);
    }

    /**
     * 200 OK with data envelope.
     */
    public static function ok(mixed $data, array $headers = []): ResponseInterface
    {
        return self::create($data, 200, $headers);
    }

    /**
     * 201 Created with Location header.
     */
    public static function created(mixed $data, string $location, array $headers = []): ResponseInterface
    {
        return self::create($data, 201, array_merge($headers, ['Location' => $location]));
    }

    /**
     * 204 No Content.
     */
    public static function noContent(array $headers = []): ResponseInterface
    {
        return new Response(204, $headers);
    }
}
