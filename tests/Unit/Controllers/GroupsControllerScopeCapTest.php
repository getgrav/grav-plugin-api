<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\GroupsController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-jqgq-v53x-x99g regression: the four super-gated write guards used to do a
 * bare isSuperAdmin() early-return, which fires BEFORE requirePermission() — the
 * sole place the API-key scope cap (GHSA-x7hm / CVE-2026-62231) runs. A scoped
 * key minted on a super account therefore passed the guard and performed
 * super-only writes uncapped. The guards now route through requireSuper(), so the
 * scope cap runs first.
 *
 * GroupsController::requireSuperOrAdmin() is the representative guard; all four
 * (groups, accounts-config, site-preferences, dashboard-layout) share the same
 * requireSuper() helper. We drive the guard directly via reflection — it is the
 * whole security boundary, so testing it in isolation is the right altitude.
 */
#[CoversClass(GroupsController::class)]
class GroupsControllerScopeCapTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function scoped_readonly_key_on_super_account_is_capped(): void
    {
        // The exact bypass: a super account's [api.pages.read] key must NOT reach
        // the super-only group write.
        $this->expectException(ForbiddenException::class);
        $this->guard($this->super(), ['api.pages.read']);
    }

    #[Test]
    public function unscoped_super_credential_still_passes(): void
    {
        // A session, JWT, or unscoped key (empty scope list) on a super account
        // keeps full access — the cap only bites non-empty scope sets.
        $this->guard($this->super(), []);
        $this->addToAssertionCount(1); // no exception == pass
    }

    #[Test]
    public function super_scoped_key_still_passes(): void
    {
        // A key explicitly scoped to admin.super (or *) is allowed through.
        $this->guard($this->super(), ['admin.super']);
        $this->guard($this->super(), ['*']);
        $this->addToAssertionCount(1);
    }

    // Note: the non-super rejection path is unchanged by this fix and descends
    // into PermissionResolver/hasPermission(), which needs a full Grav bootstrap
    // (integration tier). The scope-cap contract this fix is about — the cap runs
    // before the super short-circuit — is proven entirely by the cases above,
    // which throw (or pass) at the cap check before any Grav ACL interaction.

    private function super(): UserInterface
    {
        // createMockUser does flat-key lookup, and isSuperAdmin() reads the dotted
        // 'access.api.super' path, so seed that literal key.
        return TestHelper::createMockUser('root', ['access.api.super' => true]);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function guard(UserInterface $user, array $scopes): void
    {
        Grav::resetInstance();
        $controller = new GroupsController(Grav::instance(), new Config());
        $attributes = ['api_user' => $user];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }
        $request = TestHelper::createMockRequest(
            'POST',
            '/groups',
            attributes: $attributes,
        );

        $ref = new \ReflectionMethod($controller, 'requireSuperOrAdmin');
        $ref->invoke($controller, $request);
    }
}
