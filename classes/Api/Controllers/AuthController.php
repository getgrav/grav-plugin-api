<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\User\Authentication;
use Grav\Common\User\Interfaces\UserCollectionInterface;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\UnauthorizedException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthController extends AbstractApiController
{
    public function token(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['username', 'password']);

        $username = $body['username'];
        $password = $body['password'];

        /** @var UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);

        if (!$user->exists() || !Authentication::verify($password, $user->get('hashed_password'))) {
            throw new UnauthorizedException('Invalid username or password.');
        }

        // Verify user has API access (use direct access check, not authorize() which needs admin context)
        if (!$this->isSuperAdmin($user) && !$this->hasPermission($user, 'api.access')) {
            throw new ForbiddenException('API access is not enabled for this user.');
        }

        // Check user is not disabled
        if ($user->get('state', 'enabled') === 'disabled') {
            throw new ForbiddenException('This user account is disabled.');
        }

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $accessToken = $jwt->generateAccessToken($user);
        $refreshToken = $jwt->generateRefreshToken($user);
        $expiresIn = (int) $this->config->get('plugins.api.auth.jwt_expiry', 3600);

        return ApiResponse::create([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]);
    }

    public function refresh(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['refresh_token']);

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $user = $jwt->validateRefreshToken($body['refresh_token']);

        if ($user === null) {
            throw new UnauthorizedException('Invalid or expired refresh token.');
        }

        // Check user is still active
        if ($user->get('state', 'enabled') === 'disabled') {
            throw new ForbiddenException('This user account is disabled.');
        }

        // Revoke the old refresh token (rotation)
        $jwt->revokeToken($body['refresh_token']);

        // Generate new token pair
        $accessToken = $jwt->generateAccessToken($user);
        $refreshToken = $jwt->generateRefreshToken($user);
        $expiresIn = (int) $this->config->get('plugins.api.auth.jwt_expiry', 3600);

        return ApiResponse::create([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
        ]);
    }

    public function revoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['refresh_token']);

        $jwt = new JwtAuthenticator($this->grav, $this->config);
        $jwt->revokeToken($body['refresh_token']);

        return ApiResponse::noContent();
    }
}
