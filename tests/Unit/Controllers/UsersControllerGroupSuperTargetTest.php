<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression for GHSA-vv8m-jqpm-38x4: the target-super guards read only the
 * account's own access map, so an account that is super purely through group
 * membership was invisible to them while being fully super at authorization
 * time. A non-super api.users.write manager could reset its password and take
 * it over.
 */
class UsersControllerGroupSuperTargetTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function configWithSuperGroup(): Config
    {
        return new Config([
            'groups' => [
                'admins' => ['access' => ['api' => ['super' => true]]],
            ],
        ]);
    }

    private function groupSuperTarget(string $username): UserInterface
    {
        // Own access carries NO super flag; super comes only from the group.
        return TestHelper::createMockUser($username, [
            'access' => ['site' => ['login' => true]],
            'groups' => ['admins'],
        ]);
    }

    private function invoke(UserInterface $current, array $scopes, UserInterface $target, Config $config): void
    {
        Grav::resetInstance();
        $controller = new UsersController(Grav::instance(), $config);

        $attributes = ['api_user' => $current];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }
        $request = TestHelper::createMockRequest('POST', '/users/x', attributes: $attributes);

        $ref = new \ReflectionMethod($controller, 'requireNotSuperTarget');
        $ref->invoke($controller, $request, $target);
    }

    #[Test]
    public function a_group_inherited_super_target_is_protected(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Only super-admins can manage super-admin accounts.');

        $this->invoke(
            current: TestHelper::createMockUser('manager', ['access' => ['api' => ['users' => ['write' => true]]]]),
            scopes: ['api.users.write'],
            target: $this->groupSuperTarget('boss'),
            config: $this->configWithSuperGroup(),
        );
    }

    #[Test]
    public function a_plain_group_member_is_unaffected(): void
    {
        // A group that does NOT grant super must not trip the guard.
        $config = new Config(['groups' => ['authors' => ['access' => ['site' => ['login' => true]]]]]);

        $this->invoke(
            current: TestHelper::createMockUser('manager', ['access' => ['api' => ['users' => ['write' => true]]]]),
            scopes: ['api.users.write'],
            target: TestHelper::createMockUser('bob', ['access' => [], 'groups' => ['authors']]),
            config: $config,
        );

        $this->addToAssertionCount(1);
    }
}
