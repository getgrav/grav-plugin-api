<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigScopes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see ConfigScopes::isCustom()} — the gate that lets the cookbook "add a
 * custom yaml file" recipe surface as a config tab in admin2 while keeping
 * core/system blueprints (and path traversal) out.
 *
 * A custom scope is valid only when a user:// or environment:// blueprint
 * exists for it; core scopes and system-shipped blueprints (e.g. streams) are
 * rejected so the generic api.config.write permission can't reach them.
 */
class ConfigScopesTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-scopes-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/blueprints/config', 0777, true);
        // A site-authored top-level config blueprint (the recipe).
        file_put_contents(
            $this->tmp . '/user/blueprints/config/custom.yaml',
            "title: Custom Settings\nform:\n  fields:\n    my_text:\n      type: text\n",
        );

        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new ScopesFakeLocator($this->tmp);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            $this->rrmdir($this->tmp);
            $this->tmp = null;
        }
        Grav::resetInstance();
    }

    #[Test]
    public function user_authored_blueprint_is_a_custom_scope(): void
    {
        $this->assertTrue(ConfigScopes::isCustom(Grav::instance(), 'custom'));
    }

    #[Test]
    public function core_scopes_are_not_custom(): void
    {
        foreach (ConfigScopes::CORE as $scope) {
            $this->assertFalse(
                ConfigScopes::isCustom(Grav::instance(), $scope),
                "{$scope} is a core scope and must not be treated as custom",
            );
        }
    }

    #[Test]
    public function scope_without_a_user_blueprint_is_rejected(): void
    {
        // `streams` ships a system blueprint, not a user one — the FakeLocator
        // only resolves user://, so this stands in for "core blueprint exists
        // but not under user://".
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), 'streams'));
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), 'nope'));
    }

    #[Test]
    public function unsafe_scope_names_are_rejected_before_any_lookup(): void
    {
        foreach (['../etc/passwd', 'a/b', 'a.b', 'Custom', '-leading', '', 'a b'] as $scope) {
            $this->assertFalse(
                ConfigScopes::isCustom(Grav::instance(), $scope),
                "unsafe scope '{$scope}' must be rejected",
            );
        }
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), null));
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}

/**
 * Minimal locator resolving only the user:// blueprints stream ConfigScopes
 * checks. environment:// is intentionally absent (never set in this fixture)
 * so it resolves to false, exercising the user-only path.
 */
class ScopesFakeLocator
{
    public function __construct(private string $root) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        $prefix = 'user://';
        if (!str_starts_with($uri, $prefix)) {
            return false;
        }
        $full = $this->root . '/user/' . substr($uri, strlen($prefix));
        return file_exists($full) ? $full : false;
    }
}
