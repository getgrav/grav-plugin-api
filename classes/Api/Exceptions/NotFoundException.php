<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class NotFoundException extends ApiException
{
    public function __construct(string $detail = 'The requested resource was not found.', ?\Throwable $previous = null)
    {
        parent::__construct(404, 'Not Found', $detail, [], $previous);
    }
}
