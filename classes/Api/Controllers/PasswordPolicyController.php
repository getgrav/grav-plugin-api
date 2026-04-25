<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Services\PasswordPolicyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public endpoint that exposes the configured password policy so the
 * setup, password-reset and user-creation flows can render matching
 * client-side validation and a strength meter.
 */
class PasswordPolicyController extends AbstractApiController
{
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create(PasswordPolicyService::build($this->config));
    }
}
