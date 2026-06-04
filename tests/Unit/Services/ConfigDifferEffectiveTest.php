<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ConfigDiffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * {@see ConfigDiffer::effective()} — the per-environment, file-based config
 * resolution the admin reads and edits. This is what makes base/"Default"
 * show base config while a hostname overlay is active, instead of leaking the
 * overlay into both modes.
 *
 * Mirrors the real bug: kimi.api_key is `sk-A7…` in user/config but `32433…`
 * in user/env/localhost/config — "Default" must read the former, "localhost"
 * the latter.
 *
 * Needs Grav core on the classpath for the YAML parser. Run inside a Grav
 * install or with GRAV_ROOT set (e.g. `GRAV_ROOT=/path/to/grav composer test`).
 */
class ConfigDifferEffectiveTest extends TestCase
{
    private ?string $tmp = null;
    private ConfigDiffer $differ;

    private const SCOPE = 'plugins/translation-service';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-effective-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/config/plugins', 0777, true);
        mkdir($this->tmp . '/user/plugins/translation-service', 0777, true);
        mkdir($this->tmp . '/user/env/localhost/config/plugins', 0777, true);

        // Plugin's own defaults: kimi key empty by default.
        file_put_contents(
            $this->tmp . '/user/plugins/translation-service/translation-service.yaml',
            "enabled: true\nkimi:\n  model_bulk: kimi-k2.6\n  api_key: ''\n",
        );
        // Base user/config: real base key.
        file_put_contents(
            $this->tmp . '/user/config/plugins/translation-service.yaml',
            "kimi:\n  api_key: 'sk-A7base'\n",
        );
        // localhost env overlay: different key.
        file_put_contents(
            $this->tmp . '/user/env/localhost/config/plugins/translation-service.yaml',
            "kimi:\n  api_key: '32433overlay'\n",
        );

        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new EffectiveFakeLocator($this->tmp);
        $this->differ = new ConfigDiffer($grav);
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
    public function base_target_reads_user_config_not_the_env_overlay(): void
    {
        $effective = $this->differ->effective(self::SCOPE, null);

        $this->assertSame('sk-A7base', $effective['kimi']['api_key']);
        // Default from the plugin yaml survives where neither layer overrides it.
        $this->assertSame('kimi-k2.6', $effective['kimi']['model_bulk']);
        $this->assertTrue($effective['enabled']);
    }

    #[Test]
    public function env_target_reads_the_overlay_on_top_of_base(): void
    {
        $effective = $this->differ->effective(self::SCOPE, 'localhost');

        $this->assertSame('32433overlay', $effective['kimi']['api_key']);
        $this->assertSame('kimi-k2.6', $effective['kimi']['model_bulk']);
    }

    #[Test]
    public function unknown_env_target_falls_back_to_base(): void
    {
        // No user/env/staging folder → overlay layer contributes nothing.
        $effective = $this->differ->effective(self::SCOPE, 'staging');

        $this->assertSame('sk-A7base', $effective['kimi']['api_key']);
    }

    #[Test]
    public function effective_is_empty_when_scope_has_no_files(): void
    {
        $this->assertSame([], $this->differ->effective('plugins/ghost', null));
        $this->assertSame([], $this->differ->effective('plugins/ghost', 'localhost'));
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
 * Locator covering the stream prefixes ConfigDiffer::effective() and
 * EnvironmentService touch (user://, user://config, plugins://).
 */
class EffectiveFakeLocator
{
    public function __construct(private string $root) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        $map = [
            'user://'         => $this->root . '/user',
            'user://config'   => $this->root . '/user/config',
            'system://config' => $this->root . '/system/config',
            'plugins://'      => $this->root . '/user/plugins',
            'themes://'       => $this->root . '/user/themes',
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
