<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class ConflictException extends ApiException
{
    public function __construct(string $detail = 'The resource has been modified. Refresh and try again.', ?\Throwable $previous = null)
    {
        parent::__construct(409, 'Conflict', $detail, [], $previous);
    }
}
