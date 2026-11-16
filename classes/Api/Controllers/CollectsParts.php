<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ApiException;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Shared by the endpoints that answer several requests in one
 * (GET /admin-next/boot, GET /admin-next/dashboard): run each part on its
 * own, keep what it returns in `data` and what stopped it in `errors`, so
 * one failing part never costs the others.
 */
trait CollectsParts
{
    /**
     * @template T of AbstractApiController
     * @param class-string<T> $class
     * @return T
     */
    protected function controller(string $class): AbstractApiController
    {
        return new $class($this->grav, $this->config);
    }

    /**
     * Run one part, storing its result in $data or its failure in $errors
     * (with the status and title its own endpoint would have answered).
     *
     * @param array<string, mixed> $data
     * @param array<string, array{status: int, title: string}> $errors
     */
    protected function collect(string $part, array &$data, array &$errors, callable $build): void
    {
        try {
            $data[$part] = $build();
        } catch (ApiException $e) {
            $errors[$part] = ['status' => $e->getStatusCode(), 'title' => $e->getErrorTitle()];
        } catch (Throwable $e) {
            try {
                $this->grav['log']->error('API: part "' . $part . '" of a combined request failed: ' . $e->getMessage(), [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            } catch (Throwable) {
                // Logging must never be the reason the other parts are lost.
            }
            $errors[$part] = ['status' => 500, 'title' => 'Internal Server Error'];
        }
    }

    /**
     * The `data` of an endpoint's own handler, called in-process with this
     * request (same user, same permission checks) and the given query
     * parameters in place of the incoming ones.
     *
     * @param class-string<AbstractApiController> $class
     * @param array<string, mixed> $query
     */
    protected function endpointData(string $class, string $method, ServerRequestInterface $request, array $query = []): mixed
    {
        $response = $this->controller($class)->$method($request->withQueryParams($query));
        $body = json_decode((string) $response->getBody(), true);
        $status = $response->getStatusCode();

        if ($status >= 400 || !is_array($body) || !array_key_exists('data', $body)) {
            throw new ApiException(
                $status >= 400 ? $status : 500,
                is_array($body) && is_string($body['title'] ?? null) ? $body['title'] : 'Internal Server Error',
            );
        }

        return $body['data'];
    }
}
