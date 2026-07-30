<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\MenubarController;
use Grav\Plugin\Api\Controllers\SsoController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Second wave of the 2026-07 scope-cap family, covering the gates the first
 * sweep walked past because they sit outside the write controllers it audited:
 *
 *  - GHSA-p57v-xhv3-mf2w — userPassesAuthorize() decided `authorize` from the
 *    account ACL, on both the super short-circuit and the permission lookup, so
 *    a scoped key saw (and on the row-action path could run) items outside its
 *    scopes.
 *  - GHSA-435x-66r2-jwv2 — BlueprintPathResolver read the super flag and
 *    api.users.write straight off the account, so an api.media.write key on a
 *    super account could target another user's account folder.
 *  - GHSA-8mjx-xjfv-9c88 — MenubarController::executeAction() advertised an
 *    `authorize` contract it never enforced at execution time.
 *  - GHSA-x72c-4jc4-8rh6 — sanitizeReturnTo() accepted a backslash, which every
 *    browser reads as a slash in an http(s) URL.
 */
class AuthorizeScopeCapTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    // ---------------------------------------------------------------- p57v

    #[Test]
    public function authorize_super_shortcut_is_capped(): void
    {
        // The bypass: a super account's narrowly scoped key must not inherit
        // super authority just because the item declares one.
        self::assertFalse(
            $this->userPassesAuthorize($this->super(), 'admin.super', ['api.pages.read'])
        );
    }

    #[Test]
    public function authorize_super_shortcut_still_works_unscoped(): void
    {
        self::assertTrue($this->userPassesAuthorize($this->super(), 'admin.super', []));
    }

    #[Test]
    public function authorize_permission_lookup_is_capped(): void
    {
        $user = $this->userWith(['api' => ['users' => ['read' => true]]]);

        // Holds the permission on the account, but the key is scoped elsewhere.
        self::assertFalse(
            $this->userPassesAuthorize($user, 'api.users.read', ['api.pages.read'])
        );
        // Same account, same item, key that does carry it.
        self::assertTrue(
            $this->userPassesAuthorize($user, 'api.users.read', ['api.users.read'])
        );
    }

    #[Test]
    public function authorize_any_of_list_is_capped_per_entry(): void
    {
        $user = $this->userWith(['api' => ['users' => ['read' => true]]]);

        // The account satisfies the second entry, but the key does not.
        self::assertFalse(
            $this->userPassesAuthorize($user, ['api.super', 'api.users.read'], ['api.pages.read'])
        );
        self::assertTrue(
            $this->userPassesAuthorize($user, ['api.super', 'api.users.read'], ['api.users.read'])
        );
    }

    #[Test]
    public function authorize_null_requirement_is_always_allowed(): void
    {
        self::assertTrue($this->userPassesAuthorize($this->plain(), null, ['api.pages.read']));
    }

    #[Test]
    public function authorize_unknown_shape_fails_closed(): void
    {
        // Non-super: a super caller legitimately short-circuits before the shape
        // is ever inspected, so it cannot demonstrate the fail-closed default.
        self::assertFalse($this->userPassesAuthorize($this->plain(), 42, []));
    }

    // ---------------------------------------------------------------- 435x

    #[Test]
    public function may_write_users_scope_is_capped(): void
    {
        // api.media.write key on a super account: the exact 435x credential.
        self::assertFalse($this->mayWriteUsersScope($this->super(), ['api.media.write']));

        // The sibling the report missed — users.write read off a non-super account.
        $manager = $this->userWith(['api' => ['users' => ['write' => true]]]);
        self::assertFalse($this->mayWriteUsersScope($manager, ['api.media.write']));

        // Legitimate callers still resolve.
        self::assertTrue($this->mayWriteUsersScope($this->super(), []));
        self::assertTrue($this->mayWriteUsersScope($manager, ['api.users.write']));
    }

    // ---------------------------------------------------------------- 8mjx

    #[Test]
    public function menubar_action_refuses_when_the_item_authorize_is_unmet(): void
    {
        $this->expectExceptionMessageMatches('/Missing required permission/');

        $this->assertMenubarAction(
            $this->plain(),
            [['plugin' => 'git-sync', 'action' => 'sync', 'authorize' => 'api.git-sync.write']]
        );
    }

    #[Test]
    public function menubar_action_allows_when_the_item_authorize_is_met(): void
    {
        $user = $this->userWith(['api' => ['git-sync' => ['write' => true]]]);

        $this->assertMenubarAction(
            $user,
            [['plugin' => 'git-sync', 'action' => 'sync', 'authorize' => 'api.git-sync.write']]
        );

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function menubar_action_without_a_registered_item_keeps_the_baseline_gate(): void
    {
        // rsync/seo-magic register the handler unconditionally but the item
        // conditionally; failing closed here would break their field widgets.
        $this->assertMenubarAction($this->plain(), []);

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------------------------------- x72c

    #[Test]
    public function sanitize_return_to_rejects_browser_slash_equivalents(): void
    {
        foreach (['/\\evil.com', '/\\/evil.com', "/\t/evil.com", "/\n\\evil.com", "/\r/evil.com"] as $payload) {
            self::assertSame('', $this->sanitizeReturnTo($payload), json_encode($payload));
        }
    }

    #[Test]
    public function sanitize_return_to_still_rejects_the_originally_covered_shapes(): void
    {
        foreach (['', '//evil.com', 'https://evil.com', 'evil.com'] as $payload) {
            self::assertSame('', $this->sanitizeReturnTo($payload), json_encode($payload));
        }
    }

    #[Test]
    public function sanitize_return_to_keeps_ordinary_in_app_paths(): void
    {
        foreach (['/', '/dashboard', '/pages/foo?x=1#frag', '/%5Cevil.com'] as $payload) {
            self::assertSame($payload, $this->sanitizeReturnTo($payload), json_encode($payload));
        }
    }

    // ---------------------------------------------------------------- rig

    private function super(): UserInterface
    {
        return TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);
    }

    private function plain(): UserInterface
    {
        return TestHelper::createMockUser('bob', []);
    }

    /** @param array<string, mixed> $access */
    private function userWith(array $access): UserInterface
    {
        return TestHelper::createMockUser('editor', ['access' => $access]);
    }

    /** @param array<int, string> $scopes */
    private function userPassesAuthorize(UserInterface $user, mixed $authorize, array $scopes): bool
    {
        $controller = $this->controller(MenubarController::class);
        $ref = new ReflectionMethod($controller, 'userPassesAuthorize');

        return (bool) $ref->invoke($controller, $user, $authorize, $this->request($user, $scopes));
    }

    /** @param array<int, string> $scopes */
    private function mayWriteUsersScope(UserInterface $user, array $scopes): bool
    {
        $controller = $this->controller(MenubarController::class);
        $ref = new ReflectionMethod($controller, 'mayWriteUsersScope');

        return (bool) $ref->invoke($controller, $this->request($user, $scopes));
    }

    private function sanitizeReturnTo(string $returnTo): string
    {
        $controller = $this->controller(SsoController::class);
        $ref = new ReflectionMethod($controller, 'sanitizeReturnTo');

        return (string) $ref->invoke($controller, $returnTo);
    }

    /**
     * Drive assertActionAuthorized() with a synthetic menubar registry.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function assertMenubarAction(UserInterface $user, array $items): void
    {
        $controller = $this->controller(MenubarController::class);

        Grav::instance()->addListener(
            'onApiMenubarItems',
            static function ($event) use ($items) {
                $event['items'] = $items;
            }
        );

        $ref = new ReflectionMethod($controller, 'assertActionAuthorized');
        $ref->invoke($controller, $this->request($user, []), 'git-sync', 'sync');
    }

    /** @param class-string $class */
    private function controller(string $class): object
    {
        TestHelper::createMockGrav(['config' => new Config([])]);

        return new $class(Grav::instance(), new Config([]));
    }

    /** @param array<int, string> $scopes */
    private function request(UserInterface $user, array $scopes): \Psr\Http\Message\ServerRequestInterface
    {
        $attributes = ['api_user' => $user];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }

        return TestHelper::createMockRequest('POST', '/x', attributes: $attributes);
    }
}

