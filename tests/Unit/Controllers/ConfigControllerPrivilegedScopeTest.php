<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GHSA-wx62 regression: the scheduler config scope feeds custom_jobs[].command
 * into a Symfony Process, so writing it through the generic config endpoint
 * must require API super authority — a non-super holder of api.config.write
 * must not reach it. We drive assertScopeAllowed() directly via reflection
 * rather than a full update() round-trip; the guard is the whole security
 * boundary, so testing it in isolation is the right altitude.
 */
#[CoversClass(ConfigController::class)]
class ConfigControllerPrivilegedScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function non_super_is_blocked_from_scheduler_scope(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->guard($this->nonSuper(), 'scheduler');
    }

    #[Test]
    public function non_super_is_blocked_from_backups_scope(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->guard($this->nonSuper(), 'backups');
    }

    #[Test]
    public function super_may_access_scheduler_scope(): void
    {
        $this->guard($this->super(), 'scheduler');
        $this->addToAssertionCount(1); // no exception == pass
    }

    #[Test]
    public function ordinary_scope_is_unaffected_for_non_super(): void
    {
        // A non-super configuration admin can still manage e.g. system config.
        $this->guard($this->nonSuper(), 'system');
        $this->addToAssertionCount(1);
    }

    private function nonSuper(): UserInterface
    {
        return TestHelper::createMockUser('config-admin', [
            'access' => ['api' => ['access' => true, 'config' => ['write' => true]]],
        ]);
    }

    private function super(): UserInterface
    {
        // createMockUser does flat-key lookup, and isSuperAdmin() reads the
        // dotted 'access.api.super' path, so seed that literal key.
        return TestHelper::createMockUser('root', ['access.api.super' => true]);
    }

    private function guard(UserInterface $user, string $scope): void
    {
        Grav::resetInstance();
        $controller = new ConfigController(Grav::instance(), new Config());
        $request = TestHelper::createMockRequest(
            'PATCH',
            "/config/{$scope}",
            attributes: ['api_user' => $user],
        );

        $ref = new \ReflectionMethod($controller, 'assertScopeAllowed');
        $ref->invoke($controller, $request, $scope);
    }
}
