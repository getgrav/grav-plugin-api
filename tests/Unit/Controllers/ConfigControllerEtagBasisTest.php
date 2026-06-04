<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\ConfigController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for getgrav/grav-plugin-admin2#28.
 *
 * Toggling a checkbox whose sibling sits at its default value (e.g. unchecking
 * `system.pages.events.page` while `events.twig` stays at its default `true`)
 * used to 409 on the *second* save. The ETag was hashed from the full merged
 * config: right after a save the in-memory config still carries the
 * default-equal `twig: true`, but writeConfigFile() persists only the delta,
 * so on the next request the reloaded config no longer reports it and the hash
 * shifts under the client's stored If-Match.
 *
 * The fix keys the ETag off the persisted delta instead. The delta is
 * invariant across the save→reload round-trip — a default-equal value is
 * stripped on both sides — so the basis (and therefore the ETag) is stable.
 */
#[CoversClass(ConfigController::class)]
class ConfigControllerEtagBasisTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-etagbasis-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/system/config', 0777, true);
        mkdir($this->tmp . '/user/config', 0777, true);

        // Grav core defaults: both event flags default to true.
        file_put_contents(
            $this->tmp . '/system/config/system.yaml',
            "pages:\n  events:\n    page: true\n    twig: true\n",
        );
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
    public function etag_basis_is_stable_across_the_save_reload_round_trip(): void
    {
        // Right after saving page:false — config->set() left the full merged
        // value in memory, default-equal twig:true included.
        $postSave = $this->etagBasis([
            'pages' => ['events' => ['page' => false, 'twig' => true]],
        ]);

        // Next request — config reloaded from the persisted delta, which never
        // stored the default-equal twig:true.
        $reloaded = $this->etagBasis([
            'pages' => ['events' => ['page' => false]],
        ]);

        // Both collapse to the same persisted delta, so the ETag is unchanged
        // and the client's If-Match still validates.
        $this->assertSame(['pages' => ['events' => ['page' => false]]], $postSave);
        $this->assertSame($postSave, $reloaded);
    }

    #[Test]
    public function etag_basis_ignores_key_order(): void
    {
        $a = $this->etagBasis(['pages' => ['events' => ['page' => false, 'twig' => true]]]);
        // Same logical config, different key insertion order.
        $b = $this->etagBasis(['pages' => ['events' => ['twig' => true, 'page' => false]]]);

        $this->assertSame($a, $b);
    }

    /**
     * Drive the private configEtagBasis() for the system scope with a given
     * in-memory config snapshot.
     *
     * @param array<mixed> $systemConfig
     * @return array<mixed>
     */
    private function etagBasis(array $systemConfig): array
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new EtagFakeLocator($this->tmp);

        // effectiveConfig() resolves from the persisted YAML files (not the live
        // in-memory snapshot), so it stays target-exact behind a reverse proxy
        // where the booted config env and $uri->environment() disagree. Persist
        // the base override the way writeConfigFile() would, then read it back —
        // which is exactly the save→reload round-trip this regression guards.
        file_put_contents(
            $this->tmp . '/user/config/system.yaml',
            \Grav\Common\Yaml::dump($systemConfig),
        );

        $controller = new ConfigController($grav, new Config());

        $ref = new \ReflectionMethod($controller, 'configEtagBasis');
        return $ref->invoke($controller, 'system', null);
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
 * Minimal locator resolving the streams ConfigDiffer::parent() touches.
 */
class EtagFakeLocator
{
    public function __construct(private string $root) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        $map = [
            'user://'         => $this->root . '/user',
            'user://config'   => $this->root . '/user/config',
            'system://config' => $this->root . '/system/config',
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
