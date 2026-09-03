<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A plugin page can say its settings live on the plugin's own page, so
 * admin-next sends /plugins/{slug} there instead of drawing a second copy of
 * the same blueprint form.
 *
 * What is asserted here is the contract admin-next relies on: the key survives
 * when it names a hash route inside the plugin's page, and is dropped when it
 * names anything else, so a page definition cannot use it to point the admin at
 * an arbitrary address.
 */
#[CoversClass(GpmController::class)]
class GpmControllerSettingsRouteTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_gpm_settings_route_' . uniqid();
        mkdir($this->tempDir . '/cache', 0775, true);
        mkdir($this->tempDir . '/plugins/demo/admin-next/pages', 0775, true);
        mkdir($this->tempDir . '/plugins/plain', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    #[Test]
    public function a_hash_route_survives(): void
    {
        $this->writePageYaml("settings_route: '#/settings'");

        $definition = $this->resolve('demo');

        $this->assertSame('#/settings', $definition['settings_route'] ?? null);
    }

    #[Test]
    public function surrounding_whitespace_is_trimmed(): void
    {
        $this->writePageYaml("settings_route: '  #/settings  '");

        $definition = $this->resolve('demo');

        $this->assertSame('#/settings', $definition['settings_route'] ?? null);
    }

    #[Test]
    public function a_route_that_is_not_a_hash_route_is_dropped(): void
    {
        $this->writePageYaml("settings_route: '/plugins/somewhere-else'");

        $definition = $this->resolve('demo');

        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function a_page_that_says_nothing_has_no_settings_route(): void
    {
        $this->writePageYaml('');

        $definition = $this->resolve('demo');

        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function a_plugin_with_no_admin_page_has_no_settings_route(): void
    {
        $this->boot();
        $controller = new GpmController(\Grav\Common\Grav::instance(), $this->config());
        $request = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/gpm/plugins',
            attributes: ['api_user' => $this->user()],
        );

        $method = new \ReflectionMethod(GpmController::class, 'pluginSettingsRoute');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($controller, 'plain', $request));
    }

    private function writePageYaml(string $extra): void
    {
        $yaml = <<<YAML
        id: demo
        plugin: demo
        title: Demo
        page_type: component
        YAML;

        file_put_contents(
            $this->tempDir . '/plugins/demo/admin-next/pages/demo.yaml',
            $yaml . "\n" . $extra . "\n",
        );
    }

    /** @return array<string, mixed> */
    private function resolve(string $slug): array
    {
        $this->boot();
        $controller = new GpmController(\Grav\Common\Grav::instance(), $this->config());

        $method = new \ReflectionMethod(GpmController::class, 'resolvePluginPageDefinition');
        $method->setAccessible(true);

        return $method->invoke($controller, $slug, null) ?? [];
    }

    private function config(): Config
    {
        return new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
    }

    private function user(): object
    {
        return TestHelper::createMockUser('auditor', [
            'access' => ['api' => ['access' => true, 'gpm' => ['read' => true]]],
        ]);
    }

    private function boot(): void
    {
        TestHelper::createMockGrav([
            'config' => $this->config(),
            'locator' => new GpmSettingsRouteTestLocator($this->tempDir),
            'permissions' => new Permissions(),
            'events' => new GpmSettingsRouteTestEvents(),
            'debugger' => new GpmSettingsRouteTestDebugger(),
        ]);
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $item);
        }
        rmdir($path);
    }
}

final class GpmSettingsRouteTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
    {
        if (str_starts_with($uri, 'cache://')) {
            return $this->base . '/cache';
        }

        if (str_starts_with($uri, 'user://')) {
            $path = rtrim($this->base . '/' . ltrim(substr($uri, strlen('user://')), '/'), '/');

            return is_dir($path) || is_file($path) ? $path : false;
        }

        return false;
    }
}

/** No plugin is listening in this test, so dispatch simply hands the event back. */
final class GpmSettingsRouteTestEvents
{
    public function dispatch(object $event, ?string $eventName = null): object
    {
        return $event;
    }
}

final class GpmSettingsRouteTestDebugger
{
    public function enabled(): bool
    {
        return false;
    }
}
