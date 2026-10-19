<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Demo\DemoPolicy;
use Grav\Plugin\Api\Exceptions\DemoModeException;

/**
 * Refuses writes from demo accounts before any controller runs.
 *
 * A demo account (access.api.demo) browses everything, typically as a super
 * user, and may only change what `plugins.api.demo.writable` grants. The route
 * decides the grant (see DemoPolicy::grantFor()): a PATCH on a page needs
 * `api.pages.update`, a DELETE on one needs `api.pages.delete`, a PATCH on
 * `/config/plugins/x` needs `api.config.plugins`. That is precise enough to be
 * the decision itself, so a request let through here is marked on the request
 * (`api_demo_grant`) and the controller's own permission check honours the mark
 * rather than asking again with its coarser permission string.
 *
 * Anything with no rule (a plugin's own route, the dashboard layout) is refused
 * fail-closed unless `plugins.api.demo.allow_routes` lists it.
 */
class DemoModeMiddleware
{
    /** Request attribute holding the grant a demo write was let through on. */
    public const ATTRIBUTE = 'api_demo_grant';

    /**
     * @param array<mixed> $writable     plugins.api.demo.writable
     * @param array<mixed> $allowRoutes  plugins.api.demo.allow_routes
     */
    public function __construct(
        private readonly array $writable = [],
        private readonly array $allowRoutes = [],
    ) {}

    /**
     * The grant a demo account's write was let through on, or null when the
     * request is not a demo write at all (not a demo account, a read, a public
     * route).
     *
     * @throws DemoModeException when a demo account attempts a refused write.
     */
    public function check(UserInterface $user, string $method, string $routePath, bool $isPublic): ?string
    {
        if (!$user->get('access.api.demo')) {
            return null;
        }
        // Public routes (/auth/*) must stay usable — a demo user still logs in.
        if ($isPublic || !DemoPolicy::isMutating($method)) {
            return null;
        }

        if (!DemoPolicy::allows($method, $routePath, DemoPolicy::expand($this->writable), $this->allowRoutes)) {
            throw new DemoModeException();
        }

        $grant = DemoPolicy::grantFor($method, $routePath);

        return $grant === DemoPolicy::DENY ? 'allow_routes' : $grant;
    }
}
