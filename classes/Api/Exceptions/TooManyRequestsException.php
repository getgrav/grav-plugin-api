<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class TooManyRequestsException extends ApiException
{
    public function __construct(string $detail = 'Too many requests.', int $retryAfter = 0, ?\Throwable $previous = null)
    {
        $headers = [];
        if ($retryAfter > 0) {
            $headers['Retry-After'] = (string) $retryAfter;
        }
        parent::__construct(429, 'Too Many Requests', $detail, $headers, $previous);
    }
}
