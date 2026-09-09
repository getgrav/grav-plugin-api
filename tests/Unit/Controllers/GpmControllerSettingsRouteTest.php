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
 * the same blueprint form. With `settings_page` it can name a different
 * plugin's page as the one that draws them, which is how an add-on with no
 * admin page of its own gets its settings inside the page of the plugin it
 * extends.
 *
 * What is asserted here is the contract admin-next relies on: the keys survive
 * when they name a hash route and, where given, an installed plugin that has an
 * admin page, and are dropped when they name anything else, so a page
 * definition cannot use them to point the admin at an arbitrary address.
 */
#[CoversClass(GpmController::class)]
class GpmControllerSettingsRouteTest extends TestCase
{
    private string $tempDir;

    /** @var array<string, mixed>|null definition the fake event hands back */
    private ?array $eventDefinition = null;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_gpm_settings_route_' . uniqid();
        mkdir($this->tempDir . '/cache', 0775, true);
        mkdir($this->tempDir . '/plugins/demo/admin-next/pages', 0775, true);
        mkdir($this->tempDir . '/plugins/plain', 0775, true);
        mkdir($this->tempDir . '/plugins/addon', 0775, true);
        $this->eventDefinition = null;
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
        $this->assertSame([], $this->target('plain'));
    }

    #[Test]
    public function a_settings_page_naming_a_plugin_with_an_admin_page_survives(): void
    {
        $this->eventDefinition = $this->addonDefinition('  demo  ', '#/section/addon/settings');

        $definition = $this->resolve('addon');

        $this->assertSame('demo', $definition['settings_page'] ?? null);
        $this->assertSame('#/section/addon/settings', $definition['settings_route'] ?? null);
    }

    #[Test]
    public function a_settings_page_naming_a_plugin_with_no_admin_page_drops_both(): void
    {
        $this->eventDefinition = $this->addonDefinition('plain', '#/settings');

        $definition = $this->resolve('addon');

        $this->assertArrayNotHasKey('settings_page', $definition);
        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function a_settings_page_naming_a_plugin_that_is_not_installed_drops_both(): void
    {
        $this->eventDefinition = $this->addonDefinition('not-installed', '#/settings');

        $definition = $this->resolve('addon');

        $this->assertArrayNotHasKey('settings_page', $definition);
        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function a_settings_page_that_is_not_a_plain_slug_drops_both(): void
    {
        $this->eventDefinition = $this->addonDefinition('../demo', '#/settings');

        $definition = $this->resolve('addon');

        $this->assertArrayNotHasKey('settings_page', $definition);
        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function a_settings_page_without_a_hash_route_drops_both(): void
    {
        $this->eventDefinition = $this->addonDefinition('demo', '/plugins/demo');

        $definition = $this->resolve('addon');

        $this->assertArrayNotHasKey('settings_page', $definition);
        $this->assertArrayNotHasKey('settings_route', $definition);
    }

    #[Test]
    public function an_add_on_with_no_page_of_its_own_still_gets_a_settings_target(): void
    {
        $this->eventDefinition = $this->addonDefinition('demo', '#/section/addon/settings');

        $this->assertSame(
            ['settings_route' => '#/section/addon/settings', 'settings_page' => 'demo'],
            $this->target('addon'),
        );
    }

    #[Test]
    public function a_plugin_that_draws_its_own_settings_has_no_settings_page(): void
    {
        $this->writePageYaml("settings_route: '#/settings'");

        $this->assertSame(['settings_route' => '#/settings'], $this->target('demo'));
    }

    /**
     * A definition of the kind a host plugin hands back for an add-on that has
     * no admin page of its own.
     *
     * @return array<string, mixed>
     */
    private function addonDefinition(string $settingsPage, string $settingsRoute): array
    {
        // The host plugin has a page of its own; that is what makes it a
        // candidate for drawing someone else's settings.
        $this->writePageYaml('');

        return [
            'id' => 'addon',
            'plugin' => 'addon',
            'settings_page' => $settingsPage,
            'settings_route' => $settingsRoute,
        ];
    }

    /** @return array<string, string> */
    private function target(string $slug): array
    {
        $this->boot();
        $controller = new GpmController(\Grav\Common\Grav::instance(), $this->config());
        $request = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/gpm/plugins',
            attributes: ['api_user' => $this->user()],
        );

        $method = new \ReflectionMethod(GpmController::class, 'pluginSettingsTarget');
        $method->setAccessible(true);

        return $method->invoke($controller, $slug, $request);
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
        $grav = TestHelper::createMockGrav([
            'config' => $this->config(),
            'locator' => new GpmSettingsRouteTestLocator($this->tempDir),
            'permissions' => new Permissions(),
            'events' => new GpmSettingsRouteTestEvents(),
            'debugger' => new GpmSettingsRouteTestDebugger(),
        ]);

        // A host plugin answering onApiPluginPageInfo for an add-on that has
        // no admin page of its own — the case `settings_page` exists for.
        $definition = $this->eventDefinition;
        if ($definition !== null) {
            $grav->addListener('onApiPluginPageInfo', static function (object $event) use ($definition): void {
                if (($event['plugin'] ?? null) === 'addon') {
                    $event['definition'] = $definition;
                }
            });
        }
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
