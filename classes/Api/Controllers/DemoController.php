<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Demo\DemoManager;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Demo-mode engine control: report reset status, capture the baseline, and
 * trigger a reset on demand.
 *
 * status() is readable by any authenticated user (it drives the admin banner
 * countdown). baseline() and reset() are super-admin only AND — because their
 * routes are in DemoModeMiddleware's hard denylist — unreachable by a demo
 * account even when that account is itself super, so a demo visitor can never
 * re-bless a vandalized state or force a reset.
 */
class DemoController extends AbstractApiController
{
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        // Authenticated users only; getUser() throws 401 otherwise.
        $this->getUser($request);

        return ApiResponse::create($this->manager()->statusPayload());
    }

    public function baseline(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $manager = $this->manager();
        if (!$manager->captureBaseline()) {
            return ApiResponse::create([
                'captured' => false,
                'message' => 'No writable demo resources are configured, so there is nothing to capture.',
                'status' => $manager->statusPayload(),
            ]);
        }

        $this->fireEvent('onApiDemoBaselineCaptured', ['status' => $manager->statusPayload()]);

        return ApiResponse::create([
            'captured' => true,
            'message' => 'Demo baseline captured.',
            'status' => $manager->statusPayload(),
        ], 200, ['X-Invalidates' => 'demo:status']);
    }

    public function reset(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireSuper($request);

        $manager = $this->manager();
        // Deliberate operator action — wait out any in-flight background reset.
        $didReset = $manager->reset(true);

        $this->fireEvent('onApiDemoReset', ['status' => $manager->statusPayload()]);

        return ApiResponse::create([
            'reset' => $didReset,
            'message' => $didReset
                ? 'Demo content reset to baseline.'
                : 'Nothing to reset — capture a baseline first.',
            'status' => $manager->statusPayload(),
        ], 200, ['X-Invalidates' => 'demo:status']);
    }

    private function manager(): DemoManager
    {
        return new DemoManager($this->grav, $this->config);
    }

    // Baseline/reset call requireSuper() inherited from AbstractApiController,
    // which runs the API-key scope cap before the super check. A previous local
    // override here checked isSuperAdmin() first and so let a scoped key on a
    // super account past the cap (GHSA-vq9w-jwj5-wfjg); it also checked the wrong
    // permission string. Removing it restores the shared, cap-first behavior.
}
