<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class ValidationException extends ApiException
{
    public function __construct(
        string $detail = 'The request data is invalid.',
        protected readonly array $errors = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(422, 'Unprocessable Entity', $detail, [], $previous);
    }

    public function getValidationErrors(): array
    {
        return $this->errors;
    }
}
