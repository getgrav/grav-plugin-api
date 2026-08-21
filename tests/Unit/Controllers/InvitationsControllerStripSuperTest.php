<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\InvitationsController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression for GHSA-m363-3hww-gcwc: stripSuperFlags() removed only the nested
 * super flag (['api' => ['super' => true]]) and left the dot-keyed form
 * (['api.super' => true]) untouched. Because PermissionResolver flattens the
 * nested form down to exactly that literal key, the two are equivalent at
 * authorization time, so a non-super api.users.write inviter could smuggle super
 * into an invitation's access payload and mint a super-admin through the public
 * accept endpoint.
 */
class InvitationsControllerStripSuperTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @return array<int, mixed>
     */
    private function strip(array $access): array
    {
        Grav::resetInstance();
        $controller = new InvitationsController(Grav::instance(), new Config());
        $ref = new \ReflectionMethod($controller, 'stripSuperFlags');

        return $ref->invoke($controller, $access);
    }

    private function grantsSuper(array $access): bool
    {
        foreach (['admin', 'api'] as $scope) {
            if (!empty($access[$scope]['super']) || !empty($access["{$scope}.super"])) {
                return true;
            }
        }

        return false;
    }

    #[Test]
    public function the_dot_keyed_super_flag_is_stripped(): void
    {
        $this->assertFalse($this->grantsSuper($this->strip(['api.super' => true])));
        $this->assertFalse($this->grantsSuper($this->strip(['admin.super' => true])));
        $this->assertFalse($this->grantsSuper($this->strip(['admin.super' => 1, 'api.super' => 1])));
    }

    #[Test]
    public function the_nested_super_flag_is_still_stripped(): void
    {
        $this->assertFalse($this->grantsSuper($this->strip(['api' => ['super' => true]])));
        $this->assertFalse($this->grantsSuper($this->strip(['admin' => ['super' => true]])));
    }

    #[Test]
    public function ordinary_grants_survive(): void
    {
        $out = $this->strip(['api' => ['pages' => true, 'super' => true], 'site' => ['login' => true]]);

        $this->assertSame(['api' => ['pages' => true], 'site' => ['login' => true]], $out);
        $this->assertFalse($this->grantsSuper($out));
    }
}
