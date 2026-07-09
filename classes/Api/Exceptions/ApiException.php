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
        protected readonly ?string $errorCode = null,
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

    /**
     * A stable machine-readable error code (e.g. `demo_mode_write_blocked`) that
     * clients can match on instead of the human-facing title, which may be
     * localized. Null for exceptions that don't define one.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}
