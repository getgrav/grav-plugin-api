<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Captcha\LoginCaptcha;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Public captcha endpoints for the unauthenticated auth flows.
 *
 * `show` is discovery — the login page asks what challenge (if any) to render,
 * the same way it asks for SSO providers. `challenge` and `redeem` are the
 * cap.js proof-of-work round-trip, served here rather than by the Form plugin
 * so admin login captcha works on a bare grav + api + admin2 install and
 * inherits this plugin's CORS and rate limiting.
 *
 * All three sit under /auth/, so they're public by the router's prefix rule.
 */
class CaptchaController extends AbstractApiController
{
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return ApiResponse::create($this->captcha()->clientConfig());
    }

    public function challenge(ServerRequestInterface $request): ResponseInterface
    {
        $captcha = $this->requireCapProvider();

        return $this->wire($captcha->challenge());
    }

    public function redeem(ServerRequestInterface $request): ResponseInterface
    {
        $captcha = $this->requireCapProvider();
        $body = $this->getRequestBody($request);

        $token = trim((string) ($body['token'] ?? ''));
        $solutions = $body['solutions'] ?? null;

        if ($token === '' || !is_array($solutions)) {
            throw new ValidationException('A challenge token and its solutions are required.');
        }

        return $this->wire($captcha->redeem($token, $solutions));
    }

    /**
     * The challenge and redeem payloads are the cap.js wire format, which the
     * client reads at the top level — so they skip this API's `data` envelope.
     */
    private function wire(array $payload): ResponseInterface
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store, max-age=0'],
            json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
        );
    }

    /**
     * These two endpoints only exist while cap is the active provider. Anything
     * else (captcha off, or a remote provider selected) has no proof-of-work to
     * serve, so the route isn't there.
     */
    private function requireCapProvider(): LoginCaptcha
    {
        $captcha = $this->captcha();

        if (!$captcha->isEnabled() || $captcha->provider() !== LoginCaptcha::PROVIDER_CAP) {
            throw new NotFoundException('Captcha challenges are not available.');
        }

        return $captcha;
    }

    private function captcha(): LoginCaptcha
    {
        return new LoginCaptcha($this->grav, $this->config);
    }
}
