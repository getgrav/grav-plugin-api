<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigScopes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see ConfigScopes::isCustom()} — the gate that lets site- or plugin-authored
 * config blueprints surface as a config tab in admin2 while keeping core/system
 * blueprints (and path traversal) out.
 *
 * A custom scope is valid only when it has a blueprint provider OUTSIDE core's
 * system:// tree and NONE inside it: the cookbook "add a custom yaml file"
 * recipe (user://), an environment override, or a third-party plugin/theme.
 * Core scopes, system-shipped blueprints (e.g. streams), and any scope that
 * collides with a core blueprint name are rejected so the generic
 * api.config.write permission can't reach them.
 */
class ConfigScopesTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-scopes-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/blueprints/config', 0777, true);
        mkdir($this->tmp . '/user/plugins/company-details/blueprints/config', 0777, true);
        mkdir($this->tmp . '/system/blueprints/config', 0777, true);

        $blueprint = "title: Custom Settings\nform:\n  fields:\n    my_text:\n      type: text\n";

        // A site-authored top-level config blueprint (the cookbook recipe).
        file_put_contents($this->tmp . '/user/blueprints/config/custom.yaml', $blueprint);
        // A third-party plugin that ships its own config tab.
        file_put_contents($this->tmp . '/user/plugins/company-details/blueprints/config/details.yaml', $blueprint);
        // Core-shipped system blueprint — must never be writable via generic config.
        file_put_contents($this->tmp . '/system/blueprints/config/streams.yaml', $blueprint);
        // A plugin trying to shadow a core scope name: present in BOTH trees.
        file_put_contents($this->tmp . '/system/blueprints/config/shadowed.yaml', $blueprint);
        file_put_contents($this->tmp . '/user/plugins/company-details/blueprints/config/shadowed.yaml', $blueprint);

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
    public function plugin_authored_blueprint_is_a_custom_scope(): void
    {
        // The migrate-grav#16 case: a plugin ships blueprints/config/details.yaml.
        $this->assertTrue(ConfigScopes::isCustom(Grav::instance(), 'details'));
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
    public function system_shipped_blueprint_is_rejected(): void
    {
        // `streams` ships a system blueprint only — resolving inside system://
        // must keep it out of the generic config form.
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), 'streams'));
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), 'nope'));
    }

    #[Test]
    public function plugin_shadowing_a_core_blueprint_name_is_rejected(): void
    {
        // Even though a plugin provides `shadowed`, it also exists in system://
        // — any system provider disqualifies the scope so a plugin can't unlock
        // a core blueprint by reusing its name.
        $this->assertFalse(ConfigScopes::isCustom(Grav::instance(), 'shadowed'));
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
 * Minimal locator modelling the two existence-checked lookups ConfigScopes
 * makes with findResource() (singular): `system://blueprints/config/<f>` for the
 * core-ships guard, and the merged `blueprints://config/<f>` stream. The merged
 * stream ranks user/, environment/, and plugin blueprint dirs ABOVE system/,
 * matching how Grav registers plugin blueprint paths (Plugins::setup()).
 * findResource() returns the first EXISTING match or false, exactly like the
 * real UniformResourceLocator.
 */
class ScopesFakeLocator
{
    /** Merged blueprints:// search roots, highest priority first (system last). */
    private array $blueprintRoots;

    public function __construct(private string $root)
    {
        $this->blueprintRoots = [
            $this->root . '/user/blueprints/config',
            $this->root . '/user/plugins/company-details/blueprints/config',
            $this->root . '/system/blueprints/config',
        ];
    }

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        $systemPrefix = 'system://blueprints/config/';
        if (str_starts_with($uri, $systemPrefix)) {
            $full = $this->root . '/system/blueprints/config/' . substr($uri, strlen($systemPrefix));
            return file_exists($full) ? $full : false;
        }

        $prefix = 'blueprints://config/';
        if (str_starts_with($uri, $prefix)) {
            $rel = substr($uri, strlen($prefix));
            foreach ($this->blueprintRoots as $base) {
                $full = $base . '/' . $rel;
                if (file_exists($full)) {
                    return $full;
                }
            }
        }
        return false;
    }
}
