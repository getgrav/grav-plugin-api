<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class ForbiddenException extends ApiException
{
    public function __construct(string $detail = 'You do not have permission to perform this action.', ?\Throwable $previous = null)
    {
        parent::__construct(403, 'Forbidden', $detail, [], $previous);
    }
}
