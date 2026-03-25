<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Response;

use Grav\Framework\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class ApiResponse
{
    /**
     * Create a standard JSON response with the data envelope.
     */
    public static function create(mixed $data, int $status = 200, array $headers = []): ResponseInterface
    {
        $body = [
            'data' => $data,
        ];

        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response($status, $headers, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
    ): ResponseInterface {
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        $body = [
            'data' => $data,
            'meta' => [
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => $totalPages,
                ],
            ],
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

        $headers = array_merge($headers, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, max-age=0',
        ]);

        return new Response($status, $headers, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 201 Created with Location header.
     */
    public static function created(mixed $data, string $location): ResponseInterface
    {
        return self::create($data, 201, ['Location' => $location]);
    }

    /**
     * 204 No Content.
     */
    public static function noContent(): ResponseInterface
    {
        return new Response(204);
    }
}
