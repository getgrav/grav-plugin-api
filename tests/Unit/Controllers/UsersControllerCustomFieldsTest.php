<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Data\Blueprint;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Regression tests for admin2#138.
 *
 * A site can extend the account blueprint (user/blueprints/user/account.yaml)
 * with its own fields. Those custom fields were dropped on save because
 * update()/create() only applied a fixed whitelist of built-in fields. The fix
 * sweeps the request body for any field the (extended) account blueprint
 * declares and persists it, while still refusing reserved/privileged fields and
 * keys the blueprint doesn't define. These tests pin that behaviour.
 */
#[CoversClass(UsersController::class)]
class UsersControllerCustomFieldsTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Set first so tearDown() has a value even when the guard below skips.
        $this->tempDir = sys_get_temp_dir() . '/grav_api_users_customfields_test_' . uniqid();

        // These tests drive a real account Blueprint through the save path, so
        // they need Grav core loaded (bootstrap finds it when the plugin is
        // symlinked into an install, or via GRAV_ROOT). Under the standalone
        // stub bootstrap there's no Blueprint class — skip rather than error.
        if (!class_exists(Blueprint::class)) {
            $this->markTestSkipped('Grav core (Data\\Blueprint) not available in the stub test environment.');
        }

        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir)) {
            $this->rmrf($this->tempDir);
        }
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * A minimal account blueprint standing in for the core `user/account`
     * blueprint merged with a site extension: the built-in email field, a
     * custom text field, and the privileged access matrix.
     */
    private function accountBlueprint(): Blueprint
    {
        $blueprint = new Blueprint('user/account', [
            'form' => [
                'validation' => 'loose',
                'fields' => [
                    'email' => [
                        'type' => 'email',
                    ],
                    'custom_field1' => [
                        'type' => 'text',
                        'label' => 'Custom Field 1',
                    ],
                    'access' => [
                        'type' => 'permissions',
                    ],
                ],
            ],
        ]);

        // Init lazily on first schema() access, by which point buildController()
        // has populated the Grav container (plugins/config) that init() reads.
        return $blueprint;
    }

    private function buildController(UserInterface $targetUser): UsersController
    {
        $tempDir = $this->tempDir;

        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
                'pagination' => ['default_per_page' => 20, 'max_per_page' => 100],
            ], 'login' => ['twofa_enabled' => false]],
            // The blueprint XSS-safety gate (checkSafety) reads these; supply
            // Grav's defaults so a benign value validates cleanly.
            'security' => [
                'xss_whitelist' => ['admin.super'],
                'xss_enabled' => [
                    'on_events' => true,
                    'invalid_protocols' => true,
                    'moz_binding' => true,
                    'html_inline_styles' => true,
                    'dangerous_tags' => true,
                ],
                'xss_invalid_protocols' => ['javascript', 'vbscript', 'data'],
                'xss_dangerous_tags' => ['script', 'iframe', 'object', 'embed'],
            ],
        ]);

        $locator = new class ($tempDir) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
            {
                if (str_starts_with($uri, 'cache://')) {
                    return $this->base . '/cache';
                }
                return $this->base;
            }
        };

        // Blueprint validation (validateChangedFields) builds messages through
        // the language service; a pass-through translator is enough here.
        $language = new class {
            public function translate($key, $index = null): string
            {
                return is_array($key) ? (string) ($key[0] ?? '') : (string) $key;
            }
        };

        // Blueprint::initInternals() reads $grav['plugins']->formFieldTypes; an
        // empty registry is enough (no custom field types to merge in).
        $plugins = new class {
            public array $formFieldTypes = [];
        };

        TestHelper::createMockGrav([
            'config'      => $config,
            'locator'     => $locator,
            'language'    => $language,
            'plugins'     => $plugins,
            'accounts'    => TestHelper::createMockAccounts([$targetUser->username => $targetUser]),
            'permissions' => new Permissions(),
        ]);

        return new UsersController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, mixed> $body */
    private function makeRequest(UserInterface $caller, string $targetUsername, array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'PATCH',
            path: '/api/v1/users/' . $targetUsername,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user'     => $caller,
                'json_body'    => $body,
                'route_params' => ['username' => $targetUsername],
            ],
        );
    }

    #[Test]
    public function self_edit_persists_a_custom_blueprint_field(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]],
            'email'  => 'user1@example.com',
        ], true, $this->accountBlueprint());

        $controller = $this->buildController($user);

        $controller->update($this->makeRequest($user, 'user1', [
            'email'         => 'user1@example.com',
            'custom_field1' => 'hello world',
        ]));

        $this->assertSame('hello world', $user->get('custom_field1'), 'Custom blueprint field must be saved.');
    }

    #[Test]
    public function keys_not_declared_in_the_blueprint_are_ignored(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]],
        ], true, $this->accountBlueprint());

        $controller = $this->buildController($user);

        $controller->update($this->makeRequest($user, 'user1', [
            'custom_field1'   => 'kept',
            'totally_not_a_field' => 'dropped',
        ]));

        $this->assertSame('kept', $user->get('custom_field1'));
        $this->assertNull($user->get('totally_not_a_field'), 'An undefined key must never reach the user object.');
    }

    #[Test]
    public function reserved_fields_are_not_mass_assignable_through_the_sweep(): void
    {
        $user = TestHelper::createMockUser('user1', [
            'access' => ['api' => ['access' => true]],
        ], true, $this->accountBlueprint());

        $controller = $this->buildController($user);

        // hashed_password isn't a blueprint field anyway, but it's also on the
        // reserved list — a self-editor must not be able to set the credential
        // hash directly (only the gated `password` path may, via update()).
        $controller->update($this->makeRequest($user, 'user1', [
            'custom_field1'   => 'ok',
            'hashed_password' => 'pwned-hash',
        ]));

        $this->assertSame('ok', $user->get('custom_field1'));
        $this->assertNull($user->get('hashed_password'), 'hashed_password must not be settable via the custom-field sweep.');
    }
}
