<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\GroupsController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the 2026-07 scope-cap-bypass family (GHSA-95v9, -jrm3, -wvpj,
 * -2x29, -96xv, -22p9). Those controllers made a "may this caller act as super?"
 * decision with a bare isSuperAdmin(), which reads the account's super flag
 * directly and so let a scoped key minted on a super account past the API-key
 * scope cap. The fix routes those SOFT gates through two cap-aware helpers on
 * AbstractApiController — scopeAllows() and isSuperWithinScope() — which are the
 * whole boundary, so we exercise them directly via reflection.
 */
class ScopeCapHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function isSuperWithinScope_rejects_a_scoped_key_on_a_super_account(): void
    {
        // The exact bypass: a super account's [api.pages.read] key is NOT super.
        self::assertFalse($this->isSuperWithinScope($this->super(), ['api.pages.read']));
    }

    #[Test]
    public function isSuperWithinScope_allows_an_unscoped_super_credential(): void
    {
        // Session, JWT, or unscoped key (empty scope list) keeps super authority.
        self::assertTrue($this->isSuperWithinScope($this->super(), []));
    }

    #[Test]
    public function isSuperWithinScope_allows_an_explicitly_super_scoped_key(): void
    {
        self::assertTrue($this->isSuperWithinScope($this->super(), ['admin.super']));
        self::assertTrue($this->isSuperWithinScope($this->super(), ['*']));
    }

    #[Test]
    public function isSuperWithinScope_is_false_for_a_non_super_account(): void
    {
        self::assertFalse($this->isSuperWithinScope($this->plain(), []));
    }

    #[Test]
    public function scopeAllows_passes_everything_for_an_unscoped_caller(): void
    {
        self::assertTrue($this->scopeAllows([], 'admin.super'));
    }

    #[Test]
    public function scopeAllows_enforces_the_cap_for_a_scoped_caller(): void
    {
        // A scoped caller (createApiKey's subset check, GHSA-95v9) may act within
        // its scope and its children, but not outside it.
        self::assertTrue($this->scopeAllows(['api.pages'], 'api.pages.write'));
        self::assertFalse($this->scopeAllows(['api.pages.read'], 'admin.super'));
        self::assertFalse($this->scopeAllows(['api.pages.read'], 'api.users.write'));
    }

    private function super(): UserInterface
    {
        // createMockUser does flat-key lookup; isSuperAdmin() reads the dotted
        // 'access.api.super' path, so seed that literal key.
        return TestHelper::createMockUser('root', ['access.api.super' => true]);
    }

    private function plain(): UserInterface
    {
        return TestHelper::createMockUser('bob', []);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function isSuperWithinScope(UserInterface $user, array $scopes): bool
    {
        return (bool) $this->invoke('isSuperWithinScope', $user, $scopes);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function scopeAllows(array $scopes, string $permission): bool
    {
        return (bool) $this->invoke('scopeAllows', $this->plain(), $scopes, $permission);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function invoke(string $method, UserInterface $user, array $scopes, ?string $permission = null): mixed
    {
        Grav::resetInstance();
        $controller = new GroupsController(Grav::instance(), new Config());
        $attributes = ['api_user' => $user];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }
        $request = TestHelper::createMockRequest('POST', '/x', attributes: $attributes);

        $ref = new \ReflectionMethod($controller, $method);
        return $permission === null
            ? $ref->invoke($controller, $request)
            : $ref->invoke($controller, $request, $permission);
    }
}
