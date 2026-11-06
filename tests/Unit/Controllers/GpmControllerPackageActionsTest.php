<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\GPM\GPM;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Package install/update/remove/direct-install behaviour, the conditional GET
 * on package details, and which plugins' admin-next scripts are served.
 */
#[CoversClass(GpmController::class)]
class GpmControllerPackageActionsTest extends TestCase
{
    private string $tempDir;

    /** @var array<int, array{0: string, 1: ?string}> */
    private array $licenseWrites = [];

    /** @var array<string, string> */
    private array $licenses = [];

    private ?GPM $gpm = null;

    /** @var callable(string, array): (string|bool)|null */
    private $installer = null;

    /** @var callable(string, array): (string|bool)|null */
    private $uninstaller = null;

    /** @var array<string, mixed> */
    private array $pluginsConfig = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_gpm_actions_' . uniqid();
        mkdir($this->tempDir . '/cache', 0775, true);
        foreach (['widgets', 'fields'] as $dir) {
            mkdir($this->tempDir . '/plugins/demo/admin-next/' . $dir, 0775, true);
        }
        file_put_contents($this->tempDir . '/plugins/demo/admin-next/widgets/demo.js', 'export default 1;');
        file_put_contents($this->tempDir . '/plugins/demo/admin-next/fields/demo-field.js', 'export default 2;');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    // -- install: licence bookkeeping ----------------------------------------

    #[Test]
    public function install_does_not_file_a_licence_for_a_package_that_does_not_exist(): void
    {
        $gpm = $this->gpmMock();
        $gpm->method('findPackage')->willReturn(null);
        $gpm->method('checkPackagesCanBeInstalled')->willThrowException(new \RuntimeException('Package <red>typo</red> not found'));
        $this->gpm = $gpm;

        try {
            $this->controller()->install($this->post('/gpm/install', ['package' => 'typo', 'license' => 'ABCDEFGH-12345678']));
            $this->fail('Expected a validation error');
        } catch (ValidationException) {
        }

        $this->assertSame([], $this->licenseWrites);
    }

    #[Test]
    public function install_takes_the_licence_back_out_when_the_install_fails(): void
    {
        $this->gpm = $this->resolvingGpm('premium-thing');
        $this->installer = static fn () => throw new \RuntimeException('download refused');

        try {
            $this->controller()->install($this->post('/gpm/install', ['package' => 'premium-thing', 'license' => 'ABCDEFGH-12345678']));
            $this->fail('Expected the install to fail');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $this->assertSame([['premium-thing', 'ABCDEFGH-12345678'], ['premium-thing', null]], $this->licenseWrites);
    }

    #[Test]
    public function install_restores_a_key_that_was_already_on_file_when_the_install_fails(): void
    {
        $this->licenses['premium-thing'] = 'OLDKEY00-00000000';
        $this->gpm = $this->resolvingGpm('premium-thing');
        $this->installer = static fn () => false;

        try {
            $this->controller()->install($this->post('/gpm/install', ['package' => 'premium-thing', 'license' => 'ABCDEFGH-12345678']));
            $this->fail('Expected the install to fail');
        } catch (ApiException) {
        }

        $this->assertSame([['premium-thing', 'ABCDEFGH-12345678'], ['premium-thing', 'OLDKEY00-00000000']], $this->licenseWrites);
    }

    #[Test]
    public function install_keeps_the_licence_after_a_successful_install(): void
    {
        $this->gpm = $this->resolvingGpm('premium-thing');
        $this->installer = static fn () => true;

        $response = $this->controller()->install($this->post('/gpm/install', ['package' => 'premium-thing', 'license' => 'ABCDEFGH-12345678']));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([['premium-thing', 'ABCDEFGH-12345678']], $this->licenseWrites);
    }

    // -- update: not installed vs not updatable -------------------------------

    #[Test]
    public function update_of_a_package_that_is_not_installed_is_a_404(): void
    {
        $gpm = $this->gpmMock();
        $gpm->method('isPluginInstalled')->willReturn(false);
        $gpm->method('isThemeInstalled')->willReturn(false);
        $this->gpm = $gpm;

        $this->expectException(NotFoundException::class);
        $this->controller()->update($this->post('/gpm/update', ['package' => 'nope']));
    }

    #[Test]
    public function update_of_an_installed_package_that_is_current_is_a_422(): void
    {
        $gpm = $this->gpmMock();
        $gpm->method('isPluginInstalled')->willReturn(true);
        $gpm->method('isThemeInstalled')->willReturn(false);
        $gpm->method('isUpdatable')->willReturn(false);
        $this->gpm = $gpm;

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Plugin 'demo' is already up to date.");
        $this->controller()->update($this->post('/gpm/update', ['package' => 'demo']));
    }

    // -- remove: colour tags ---------------------------------------------------

    #[Test]
    public function remove_strips_cli_colour_tags_from_the_error(): void
    {
        $gpm = $this->gpmMock();
        $gpm->method('isPluginInstalled')->willReturn(true);
        $this->gpm = $gpm;
        $this->uninstaller = static fn () => throw new \RuntimeException('Could not remove <red>demo</red>');

        try {
            $this->controller()->remove($this->post('/gpm/remove', ['package' => 'demo']));
            $this->fail('Expected the removal to fail');
        } catch (ApiException $e) {
            $this->assertSame('Could not remove demo', $e->getMessage());
        }
    }

    // -- direct-install: web URLs only ----------------------------------------

    #[Test]
    public function direct_install_refuses_a_server_path(): void
    {
        foreach (['/var/www/backup/site.zip', 'backup://site.zip', 'file:///etc/site.zip', 'ftp://example.com/a.zip'] as $url) {
            try {
                $this->controller()->directInstall($this->post('/gpm/direct-install', ['url' => $url]));
                $this->fail("Expected '{$url}' to be refused");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('http', $e->getMessage());
            }
        }
    }

    #[Test]
    public function direct_install_accepts_http_and_https_addresses(): void
    {
        $method = new \ReflectionMethod(GpmController::class, 'isWebUrl');
        $controller = $this->controller();

        $this->assertTrue($method->invoke($controller, 'https://example.com/plugin.zip'));
        $this->assertTrue($method->invoke($controller, 'HTTP://example.com/plugin.zip'));
        $this->assertFalse($method->invoke($controller, 'https:///plugin.zip'));
    }

    // -- package details: conditional GET --------------------------------------

    #[Test]
    public function package_details_answer_a_matching_if_none_match_with_304(): void
    {
        $controller = $this->controller();
        $method = new \ReflectionMethod(GpmController::class, 'respondWithConditionalEtag');
        $data = ['slug' => 'demo', 'version' => '1.0.0'];

        $first = $method->invoke($controller, $this->get('/gpm/plugins/demo'), $data);
        $this->assertSame(200, $first->getStatusCode());
        $etag = $first->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);

        $second = $method->invoke($controller, $this->get('/gpm/plugins/demo', ['If-None-Match' => 'W/' . $etag]), $data);
        $this->assertSame(304, $second->getStatusCode());
        $this->assertSame($etag, $second->getHeaderLine('ETag'));
        $this->assertSame('', (string) $second->getBody());

        $changed = $method->invoke($controller, $this->get('/gpm/plugins/demo', ['If-None-Match' => $etag]), ['slug' => 'demo', 'version' => '1.0.1']);
        $this->assertSame(200, $changed->getStatusCode());
    }

    // -- admin-next scripts of disabled plugins --------------------------------

    #[Test]
    public function widget_script_of_a_disabled_plugin_is_a_404(): void
    {
        $this->pluginsConfig = ['demo' => ['enabled' => false]];

        $this->expectException(NotFoundException::class);
        $this->controller()->widgetScript($this->get('/gpm/plugins/demo/widget-script', [], ['slug' => 'demo']));
    }

    #[Test]
    public function widget_script_of_an_enabled_plugin_is_served(): void
    {
        $this->pluginsConfig = ['demo' => ['enabled' => true]];

        $response = $this->controller()->widgetScript($this->get('/gpm/plugins/demo/widget-script', [], ['slug' => 'demo']));
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function field_script_of_a_disabled_plugin_is_still_served_for_its_settings_form(): void
    {
        $this->pluginsConfig = ['demo' => ['enabled' => false]];

        $response = $this->controller()->customFieldScript(
            $this->get('/gpm/plugins/demo/field/demo-field', [], ['slug' => 'demo', 'type' => 'demo-field'])
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    // -- custom-fields permission ---------------------------------------------

    #[Test]
    public function custom_fields_needs_only_api_access(): void
    {
        $gpm = $this->gpmMock();
        $gpm->method('getInstalledPlugins')->willReturn(['demo' => (object) []]);
        $gpm->method('getInstalledThemes')->willReturn([]);
        $this->gpm = $gpm;
        $this->pluginsConfig = ['demo' => ['enabled' => true]];

        $editor = TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true]]]);
        $response = $this->controller()->allCustomFields($this->get('/custom-fields', [], [], $editor));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        // `v` (mtime-size) versions the field's module URL.
        $this->assertSame(['slug', 'kind', 'v'], array_keys($body['data']['demo-field']));
        $this->assertSame('demo', $body['data']['demo-field']['slug']);
        $this->assertSame('plugins', $body['data']['demo-field']['kind']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+-[0-9a-f]+$/', $body['data']['demo-field']['v']);
    }

    // -------------------------------------------------------------------------

    /**
     * A mock of GpmPackageActionsTestGpm: the shared GPM test stub carries only
     * the methods update-all needs, so the extra ones live on a subclass here.
     *
     * @return GpmPackageActionsTestGpm&\PHPUnit\Framework\MockObject\MockObject
     */
    private function gpmMock(): GPM
    {
        return $this->createMock(GpmPackageActionsTestGpm::class);
    }

    private function resolvingGpm(string $slug): GPM
    {
        $gpm = $this->gpmMock();
        $gpm->method('isPluginInstalled')->willReturn(false);
        $gpm->method('findPackage')->willReturn((object) ['slug' => $slug, 'premium' => null]);
        $gpm->method('getDependencies')->willReturn([]);

        return $gpm;
    }

    private function controller(): GpmController
    {
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']] + $this->pluginsConfig]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'locator' => new GpmPackageActionsTestLocator($this->tempDir),
            'permissions' => new Permissions(),
            'events' => new GpmPackageActionsTestEvents(),
            'debugger' => new GpmPackageActionsTestDebugger(),
        ]);

        $test = $this;

        return new class ($grav, $config, $test) extends GpmController {
            public function __construct($grav, $config, private readonly GpmControllerPackageActionsTest $test)
            {
                parent::__construct($grav, $config);
            }

            protected function getGpm(bool $refresh = false): GPM
            {
                return $this->test->gpmFor();
            }

            protected function installPackage(string $slug, array $options): string|bool
            {
                return $this->test->runInstaller($slug, $options);
            }

            protected function uninstallPackage(string $slug, array $options): string|bool
            {
                return $this->test->runUninstaller($slug, $options);
            }

            protected function readLicense(string $slug): string
            {
                return $this->test->licenseFor($slug);
            }

            protected function storeLicense(string $slug, ?string $license): void
            {
                $this->test->recordLicense($slug, $license);
            }
        };
    }

    /** @internal called by the controller double */
    public function gpmFor(): GPM
    {
        return $this->gpm ?? $this->gpmMock();
    }

    /** @internal */
    public function runInstaller(string $slug, array $options): string|bool
    {
        return $this->installer ? ($this->installer)($slug, $options) : true;
    }

    /** @internal */
    public function runUninstaller(string $slug, array $options): string|bool
    {
        return $this->uninstaller ? ($this->uninstaller)($slug, $options) : true;
    }

    /** @internal */
    public function licenseFor(string $slug): string
    {
        return $this->licenses[$slug] ?? '';
    }

    /** @internal */
    public function recordLicense(string $slug, ?string $license): void
    {
        $this->licenseWrites[] = [$slug, $license];
    }

    private function admin(): object
    {
        return TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]);
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1' . $path,
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: ['api_user' => $this->admin(), 'json_body' => $body],
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $routeParams
     */
    private function get(string $path, array $headers = [], array $routeParams = [], ?object $user = null): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1' . $path,
            headers: $headers,
            attributes: ['api_user' => $user ?? $this->admin(), 'route_params' => $routeParams],
        );
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

/**
 * GPM methods the package-action routes call, on top of the shared stub.
 * Real GPM has them all; the stub (tests/Stubs/GravStubs.php) does not.
 */
class GpmPackageActionsTestGpm extends GPM
{
    public function isPluginInstalled($slug): bool { return false; }
    public function isThemeInstalled($slug): bool { return false; }
    public function findPackage($search, $ignore_exception = false) { return null; }
    public function getInstalledPlugins() { return []; }
    public function getInstalledThemes() { return []; }
}

final class GpmPackageActionsTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
    {
        if (str_starts_with($uri, 'cache://')) {
            return $this->base . '/cache';
        }

        foreach (['plugins', 'themes'] as $type) {
            if (str_starts_with($uri, $type . '://')) {
                $path = $this->base . '/' . $type . '/' . substr($uri, strlen($type . '://'));

                return is_dir($path) ? $path : false;
            }
        }

        return false;
    }
}

final class GpmPackageActionsTestEvents
{
    public function dispatch(object $event, ?string $eventName = null): object
    {
        return $event;
    }
}

final class GpmPackageActionsTestDebugger
{
    public function enabled(): bool
    {
        return false;
    }
}
