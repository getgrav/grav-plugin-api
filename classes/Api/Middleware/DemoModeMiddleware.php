<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Middleware;

use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\DemoModeException;

/**
 * Coarse fail-closed backstop that blocks writes from demo accounts.
 *
 * A demo account (access.api.demo) browses everything — typically also granted
 * access.api.super — but may only mutate the resources in the configured
 * writable allowlist. The PRECISE allowlist check lives in
 * AbstractApiController::requirePermission(), which sees the exact permission
 * string (so page-content `api.pages.write` and page-media `api.media.write` are
 * distinguished even though they share the /pages route prefix).
 *
 * This middleware runs in ApiRouter::process() before dispatch and only has the
 * URL, so it's deliberately coarse. Its job is to fail closed for mutating
 * requests that would NOT otherwise reach a requirePermission() call (e.g. a
 * plugin route with no permission check): it blocks the hard denylist outright,
 * lets the content/media families through to their controllers (where the
 * precise check enforces the allowlist), and blocks everything else.
 */
class DemoModeMiddleware
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Route prefixes ALWAYS write-blocked for demo accounts. `/demo` is here
     * because a demo account is usually also super — without it the account could
     * call POST /demo/baseline (blessing a vandalized state) or POST /demo/reset.
     */
    private const HARD_DENYLIST_PREFIXES = [
        '/config', '/users', '/groups', '/invitations',
        '/gpm', '/system', '/backups', '/webhooks', '/audit', '/demo',
    ];

    /**
     * Route families whose controllers enforce the writable allowlist precisely
     * via requirePermission(). Mutating requests here are allowed to PROCEED to
     * their controller (which may still reject them); the coarse gate defers the
     * pages-vs-media decision to the permission-string check downstream.
     */
    private const ENFORCED_FAMILY_PREFIXES = ['/pages', '/media', '/blueprint-upload'];

    /**
     * @throws DemoModeException when a demo account attempts a clearly-blocked write.
     */
    public function check(UserInterface $user, string $method, string $routePath, bool $isPublic): void
    {
        if (!$user->get('access.api.demo')) {
            return;
        }
        // Public routes (/auth/*) must stay usable — a demo user still logs in.
        if ($isPublic) {
            return;
        }
        if (!in_array(strtoupper($method), self::MUTATING_METHODS, true)) {
            return;
        }

        // Hard denylist wins unconditionally.
        if ($this->matchesAnyPrefix($routePath, self::HARD_DENYLIST_PREFIXES)) {
            throw new DemoModeException();
        }

        // Content/media families defer to requirePermission() for the precise,
        // permission-string-based allowlist decision.
        if ($this->matchesAnyPrefix($routePath, self::ENFORCED_FAMILY_PREFIXES)) {
            return;
        }

        // Anything else mutating (incl. plugin routes without a permission check)
        // is blocked fail-closed.
        throw new DemoModeException();
    }

    /**
     * @param list<string> $prefixes
     */
    private function matchesAnyPrefix(string $routePath, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($routePath === $prefix || str_starts_with($routePath, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
