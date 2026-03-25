<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Exceptions;

class UnauthorizedException extends ApiException
{
    public function __construct(string $detail = 'Authentication is required.', ?\Throwable $previous = null)
    {
        parent::__construct(401, 'Unauthorized', $detail, ['WWW-Authenticate' => 'Bearer'], $previous);
    }
}
