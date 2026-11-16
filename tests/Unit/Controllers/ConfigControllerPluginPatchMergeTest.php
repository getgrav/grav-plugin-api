<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Config\Setup;
use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Controllers\ConfigController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A PATCH of part of a plugin's nested settings keeps the rest of that block.
 * The merge used the blueprint Blueprints() returns for `plugins/<name>`, which
 * is empty (the `blueprints://` stream doesn't carry plugin blueprints), and
 * with no rules every nested block was replaced: `{"magic_link":{"ttl":15}}`
 * dropped `magic_link.enabled`.
 */
#[CoversClass(ConfigController::class)]
class ConfigControllerPluginPatchMergeTest extends TestCase
{
    private ?string $tmp = null;
    private ?string $savedSetupEnv = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-plugin-patch-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/plugins/demo', 0777, true);
        mkdir($this->tmp . '/user/config/plugins', 0777, true);
        file_put_contents($this->tmp . '/user/plugins/demo/demo.yaml', Yaml::dump([
            'enabled' => true,
            'magic_link' => ['enabled' => false, 'ttl' => 60],
        ]));
        file_put_contents($this->tmp . '/user/plugins/demo/blueprints.yaml', Yaml::dump([
            'name' => 'Demo',
            'form' => ['fields' => [
                'enabled' => ['type' => 'toggle'],
                'magic_link.enabled' => ['type' => 'toggle'],
                'magic_link.ttl' => ['type' => 'number'],
            ]],
        ]));
        file_put_contents($this->tmp . '/user/config/plugins/demo.yaml', Yaml::dump([
            'magic_link' => ['enabled' => true, 'ttl' => 30],
        ]));
        $this->savedSetupEnv = Setup::$environment;
        Setup::$environment = null;
    }

    protected function tearDown(): void
    {
        Setup::$environment = $this->savedSetupEnv;
        if ($this->tmp !== null) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmp);
        }
        Grav::resetInstance();
    }

    #[Test]
    public function patching_one_nested_key_keeps_its_siblings(): void
    {
        $merged = $this->merge(['magic_link' => ['enabled' => true, 'ttl' => 30]], ['magic_link' => ['ttl' => 15]]);

        self::assertSame(['enabled' => true, 'ttl' => 15], $merged['magic_link']);
    }

    #[Test]
    public function keys_outside_the_patch_are_kept(): void
    {
        $merged = $this->merge(['enabled' => true, 'magic_link' => ['enabled' => true, 'ttl' => 30]], ['magic_link' => ['enabled' => false]]);

        self::assertSame(['enabled' => true, 'magic_link' => ['enabled' => false, 'ttl' => 30]], $merged);
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function merge(array $existing, array $body): array
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod($controller, 'mergeUpdate');

        // loadBlueprint()'s answer for a plugin: an empty schema.
        return $method->invoke($controller, 'plugins/demo', $existing, $body, new \Grav\Common\Data\Blueprint('plugins/demo'));
    }

    private function controller(): ConfigController
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $root = $this->tmp;
        $grav['locator'] = new class ($root) {
            public function __construct(private readonly string $root) {}

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                $map = [
                    'user://config' => $this->root . '/user/config',
                    'user://' => $this->root . '/user',
                    'plugins://' => $this->root . '/user/plugins',
                    'plugin://' => $this->root . '/user/plugins',
                ];
                foreach ($map as $prefix => $base) {
                    if ($uri === $prefix) {
                        return is_dir($base) || $first ? $base : false;
                    }
                    if (str_starts_with($uri, $prefix)) {
                        $sub = ltrim(substr($uri, strlen($prefix)), '/');
                        $full = $base . ($sub !== '' ? '/' . $sub : '');
                        return file_exists($full) || $first ? $full : false;
                    }
                }
                return false;
            }

            public function isStream(string $uri): bool
            {
                return false;
            }

            /** @return array<int, string> */
            public function findResources(string $uri, bool $absolute = true, bool $all = false): array
            {
                return [];
            }
        };
        $grav['cache'] = new class {
            public function clearCache(string $remove = 'standard'): array
            {
                return [];
            }
        };
        $grav['plugins'] = new class {
            public mixed $formFieldTypes = null;
        };

        $config = new Config(['plugins' => ['demo' => [
            'enabled' => true,
            'magic_link' => ['enabled' => true, 'ttl' => 30],
        ]]]);
        $grav['config'] = $config;

        return new ConfigController($grav, $config);
    }
}
