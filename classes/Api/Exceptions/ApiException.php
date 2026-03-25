<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        protected readonly int $statusCode,
        protected readonly string $errorTitle,
        string $detail = '',
        protected readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorTitle(): string
    {
        return $this->errorTitle;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
