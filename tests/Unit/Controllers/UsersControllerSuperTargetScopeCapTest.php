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
 * Regression for GHSA-94q7-vrqr-cx5v: requireNotSuperTarget() decided the super
 * exemption with a bare isSuperAdmin($current).
 *
 * The subtlety that made this survive review is that the super branch GRANTS AN
 * EXEMPTION rather than granting access. Reading super-ness off the account meant
 * a scoped key minted on a super-admin account skipped the guard entirely, so it
 * could mint API keys for, or strip 2FA from, OTHER super-admin accounts without
 * ever carrying admin.super in its scopes. The guard was strictest against the
 * least privileged caller and absent for the one whose credential was explicitly
 * scoped down.
 */
class UsersControllerSuperTargetScopeCapTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function a_scoped_key_on_a_super_account_cannot_act_on_a_super_target(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Only super-admins can manage super-admin accounts.');

        // The exact bypass: caller's ACCOUNT is super, but the presented key is
        // scoped to api.users.write only. It must not inherit the super exemption.
        $this->requireNotSuperTarget(
            current: $this->super('root'),
            scopes: ['api.users.write'],
            target: $this->super('otherroot'),
        );
    }

    #[Test]
    public function an_unscoped_super_credential_keeps_the_exemption(): void
    {
        // Session, JWT, or an unscoped key on a super account: unchanged behaviour.
        $this->requireNotSuperTarget(
            current: $this->super('root'),
            scopes: [],
            target: $this->super('otherroot'),
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function an_explicitly_super_scoped_key_keeps_the_exemption(): void
    {
        $this->requireNotSuperTarget(
            current: $this->super('root'),
            scopes: ['admin.super'],
            target: $this->super('otherroot'),
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_non_super_caller_is_still_blocked(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->requireNotSuperTarget(
            current: $this->plain('bob'),
            scopes: [],
            target: $this->super('root'),
        );
    }

    #[Test]
    public function acting_on_your_own_account_is_never_an_escalation(): void
    {
        // Self is allowed even for a scoped key on a super account — otherwise the
        // fix would lock a super-admin out of managing their own 2FA/API keys.
        $this->requireNotSuperTarget(
            current: $this->super('root'),
            scopes: ['api.users.write'],
            target: $this->super('root'),
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_non_super_target_is_unaffected(): void
    {
        $this->requireNotSuperTarget(
            current: $this->plain('manager'),
            scopes: ['api.users.write'],
            target: $this->plain('bob'),
        );

        $this->addToAssertionCount(1);
    }

    private function super(string $username): UserInterface
    {
        return TestHelper::createMockUser($username, ['access' => ['api' => ['super' => true]]]);
    }

    private function plain(string $username): UserInterface
    {
        return TestHelper::createMockUser($username, []);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function requireNotSuperTarget(UserInterface $current, array $scopes, UserInterface $target): void
    {
        Grav::resetInstance();
        $controller = new UsersController(Grav::instance(), new Config());

        $attributes = ['api_user' => $current];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }
        $request = TestHelper::createMockRequest('POST', '/users/x', attributes: $attributes);

        $ref = new \ReflectionMethod($controller, 'requireNotSuperTarget');
        $ref->invoke($controller, $request, $target);
    }
}
