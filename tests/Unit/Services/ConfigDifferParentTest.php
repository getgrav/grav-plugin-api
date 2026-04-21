<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Integration-ish tests for {@see ConfigDiffer::parent()} — the only method
 * that actually touches the filesystem. We spin up a temp directory laid
 * out like a Grav install and wire a fake locator into the Grav stub.
 *
 * Skipped when Grav core isn't on the classpath (stubs have no Yaml parser).
 */
class ConfigDifferParentTest extends TestCase
{
    private ?string $tmp = null;
    private ConfigDiffer $differ;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-differ-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/config', 0777, true);
        mkdir($this->tmp . '/user/config', 0777, true);
        mkdir($this->tmp . '/user/plugins/form', 0777, true);
        mkdir($this->tmp . '/user/themes/quark', 0777, true);

        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new FakeLocator($this->tmp);
        $this->differ = new ConfigDiffer($grav);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            $this->rrmdir($this->tmp);
            $this->tmp = null;
        }
    }

    #[Test]
    public function parent_for_system_uses_system_config_defaults(): void
    {
        file_put_contents(
            $this->tmp . '/system/config/system.yaml',
            "force_ssl: false\ntimezone: UTC\n",
        );

        $parent = $this->differ->parent('system', null);

        $this->assertSame(['force_ssl' => false, 'timezone' => 'UTC'], $parent);
    }

    #[Test]
    public function parent_for_plugin_uses_plugin_own_yaml(): void
    {
        file_put_contents(
            $this->tmp . '/user/plugins/form/form.yaml',
            "enabled: true\nfiles:\n  fields: true\n",
        );

        $parent = $this->differ->parent('plugins/form', null);

        $this->assertSame(['enabled' => true, 'files' => ['fields' => true]], $parent);
    }

    #[Test]
    public function parent_for_theme_uses_theme_own_yaml(): void
    {
        file_put_contents(
            $this->tmp . '/user/themes/quark/quark.yaml',
            "enabled: true\ndropdown:\n  enabled: false\n",
        );

        $parent = $this->differ->parent('themes/quark', null);

        $this->assertSame(['enabled' => true, 'dropdown' => ['enabled' => false]], $parent);
    }

    #[Test]
    public function env_parent_merges_defaults_with_user_config_base(): void
    {
        file_put_contents(
            $this->tmp . '/system/config/system.yaml',
            "force_ssl: false\ntimezone: UTC\npages:\n  theme: quark\n",
        );
        file_put_contents(
            $this->tmp . '/user/config/system.yaml',
            "force_ssl: true\npages:\n  theme: quark2\n",
        );

        $parent = $this->differ->parent('system', 'staging.foo.com');

        // force_ssl + pages.theme overridden by user/config; timezone stays at default.
        $this->assertSame(
            ['force_ssl' => true, 'timezone' => 'UTC', 'pages' => ['theme' => 'quark2']],
            $parent,
        );
    }

    #[Test]
    public function env_parent_falls_back_to_defaults_when_no_base_file(): void
    {
        file_put_contents(
            $this->tmp . '/system/config/system.yaml',
            "force_ssl: false\n",
        );

        $parent = $this->differ->parent('system', 'staging');

        $this->assertSame(['force_ssl' => false], $parent);
    }

    #[Test]
    public function parent_is_empty_array_when_no_defaults_exist(): void
    {
        // Theme with no defaults file — parent should be [].
        $this->assertSame([], $this->differ->parent('themes/ghost', null));
    }

    #[Test]
    public function parent_diff_round_trip_system_config(): void
    {
        // Put some defaults and user-layer overrides on disk, then verify the
        // full pipeline: compute env parent, diff the desired effective state
        // against it, and confirm we only persist the env-specific deltas.
        file_put_contents(
            $this->tmp . '/system/config/system.yaml',
            "force_ssl: false\ntimezone: UTC\nlanguages:\n  supported: [en, fr, de]\n",
        );
        file_put_contents(
            $this->tmp . '/user/config/system.yaml',
            "force_ssl: true\n",
        );

        $desiredEffective = [
            'force_ssl' => true,              // same as user/config base
            'timezone' => 'America/Denver',   // env-specific
            'languages' => ['supported' => ['en', 'fr']],  // shortened list
        ];

        $parent = $this->differ->parent('system', 'staging');
        $delta = $this->differ->diff($desiredEffective, $parent);

        $this->assertSame(
            [
                'timezone' => 'America/Denver',
                'languages' => ['supported' => ['en', 'fr']],
            ],
            $delta,
        );
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
 * Minimal locator mimicking UniformResourceLocator::findResource() for the
 * stream prefixes we use.
 */
class FakeLocator
{
    public function __construct(private string $root) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        $map = [
            'user://'          => $this->root . '/user',
            'user://config'    => $this->root . '/user/config',
            'system://config'  => $this->root . '/system/config',
            'plugins://'       => $this->root . '/user/plugins',
            'themes://'        => $this->root . '/user/themes',
        ];

        foreach ($map as $prefix => $base) {
            if ($prefix === $uri) {
                return file_exists($base) || $first ? $base : false;
            }
            if (str_starts_with($uri, $prefix)) {
                $sub = substr($uri, strlen($prefix));
                $full = $base . ($sub !== '' ? '/' . $sub : '');
                return file_exists($full) ? $full : false;
            }
        }
        return false;
    }
}
