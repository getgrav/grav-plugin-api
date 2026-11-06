<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Services\AdminModules;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GET /admin-next/modules/... serves a plugin's admin-next web component as a
 * same-origin ES module with its element tag line in front, so Admin2 can
 * import it under a strict CSP instead of building a blob: URL.
 */
#[CoversClass(GpmController::class)]
#[CoversClass(AdminModules::class)]
class GpmControllerModuleScriptTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_modules_' . uniqid();
        foreach (['plugins/shop/admin-next/pages', 'plugins/shop/admin-next/fields', 'plugins/shop/admin-next/modals',
                  'plugins/off/admin-next/pages', 'plugins/off/admin-next/fields', 'themes/quark/admin-next/fields', 'cache'] as $dir) {
            mkdir($this->tempDir . '/' . $dir, 0775, true);
        }
        file_put_contents($this->tempDir . '/plugins/shop/admin-next/pages/shop.js', "customElements.define(window.__GRAV_PAGE_TAG, class extends HTMLElement {});\n");
        file_put_contents($this->tempDir . '/plugins/shop/admin-next/fields/price.js', "export {};\n");
        file_put_contents($this->tempDir . '/plugins/shop/admin-next/modals/pick.js', "export {};\n");
        file_put_contents($this->tempDir . '/plugins/off/admin-next/pages/off.js', "export {};\n");
        file_put_contents($this->tempDir . '/plugins/off/admin-next/fields/color.js', "export {};\n");
        file_put_contents($this->tempDir . '/themes/quark/admin-next/fields/swatch.js', "export {};\n");
        file_put_contents($this->tempDir . '/secret.js', "leak();\n");
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    private function controller(): GpmController
    {
        $config = new Config(['plugins' => [
            'api' => ['route' => '/api', 'version_prefix' => 'v1'],
            'shop' => ['enabled' => true],
            'off' => ['enabled' => false],
        ]]);
        TestHelper::createMockGrav([
            'config' => $config,
            'locator' => new ModuleScriptTestLocator($this->tempDir),
        ]);

        return new GpmController(\Grav\Common\Grav::instance(), $config);
    }

    /** @param array<string, string> $params */
    private function request(string $path, array $params, array $query, array $headers = []): \Psr\Http\Message\ServerRequestInterface
    {
        // No api_user: the route is public.
        return TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/admin-next/modules/' . $path,
            headers: $headers,
            queryParams: $query,
            attributes: ['route_params' => $params],
        );
    }

    #[Test]
    public function page_module_gets_its_tag_line_and_script_headers(): void
    {
        $response = $this->controller()->moduleScript($this->request(
            'plugins/shop/page',
            ['slug' => 'shop', 'type' => 'page'],
            ['tag' => 'grav-shop--page'],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/javascript; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            "window.__GRAV_PAGE_TAG = \"grav-shop--page\";\ncustomElements.define(window.__GRAV_PAGE_TAG, class extends HTMLElement {});\n",
            (string) $response->getBody(),
        );
    }

    #[Test]
    public function a_matching_version_is_cached_for_a_year_and_an_old_one_is_not(): void
    {
        $file = $this->tempDir . '/plugins/shop/admin-next/fields/price.js';
        $v = AdminModules::version($file);
        self::assertNotNull($v);

        $controller = $this->controller();
        self::assertSame($v, AdminModules::versionFor(\Grav\Common\Grav::instance(), 'field', 'shop', 'price'));

        $fresh = $controller->moduleScript($this->request(
            'plugins/shop/field/price',
            ['slug' => 'shop', 'type' => 'field', 'name' => 'price'],
            ['tag' => 'grav-shop--price', 'v' => $v],
        ));
        self::assertSame('public, max-age=31536000, immutable', $fresh->getHeaderLine('Cache-Control'));
        self::assertSame('"' . $v . '"', $fresh->getHeaderLine('ETag'));
        self::assertStringStartsWith("window.__GRAV_FIELD_TAG = \"grav-shop--price\";\n", (string) $fresh->getBody());

        $stale = $controller->moduleScript($this->request(
            'plugins/shop/field/price',
            ['slug' => 'shop', 'type' => 'field', 'name' => 'price'],
            ['tag' => 'grav-shop--price', 'v' => '1-1'],
        ));
        self::assertSame('no-cache', $stale->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function a_matching_etag_gets_an_empty_304(): void
    {
        $v = AdminModules::version($this->tempDir . '/plugins/shop/admin-next/modals/pick.js');
        $response = $this->controller()->moduleScript($this->request(
            'plugins/shop/modal/pick',
            ['slug' => 'shop', 'type' => 'modal', 'name' => 'pick'],
            ['tag' => 'grav-shop--modal-pick'],
            ['If-None-Match' => '"' . $v . '"'],
        ));

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    /** @return iterable<string, array{string}> */
    public static function badTags(): iterable
    {
        yield 'missing' => [''];
        yield 'no hyphen' => ['gravpage'];
        yield 'uppercase' => ['Grav-Page'];
        yield 'quote breakout' => ['grav-x";alert(1);//'];
        yield 'markup' => ['grav-x</script>'];
        yield 'space' => ['grav-x y'];
        yield 'leading digit' => ['1grav-x'];
        yield 'too long' => ['grav-' . str_repeat('a', 130)];
    }

    #[Test]
    #[DataProvider('badTags')]
    public function a_tag_that_is_not_a_custom_element_name_is_refused(string $tag): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->moduleScript($this->request(
            'plugins/shop/page',
            ['slug' => 'shop', 'type' => 'page'],
            ['tag' => $tag],
        ));
    }

    #[Test]
    public function a_file_name_with_a_path_in_it_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->moduleScript($this->request(
            'plugins/shop/field/..',
            ['slug' => 'shop', 'type' => 'field', 'name' => '../../../secret'],
            ['tag' => 'grav-shop--x'],
        ));
    }

    #[Test]
    public function a_dot_dot_slug_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->controller()->moduleScript($this->request(
            'plugins/../page',
            ['slug' => '..', 'type' => 'page'],
            ['tag' => 'grav-x--page'],
        ));
    }

    #[Test]
    public function a_disabled_plugin_page_is_not_served(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->moduleScript($this->request(
            'plugins/off/page',
            ['slug' => 'off', 'type' => 'page'],
            ['tag' => 'grav-off--page'],
        ));
    }

    #[Test]
    public function a_disabled_plugin_field_is_still_served(): void
    {
        // Its settings form is edited before the plugin is enabled.
        $response = $this->controller()->moduleScript($this->request(
            'plugins/off/field/color',
            ['slug' => 'off', 'type' => 'field', 'name' => 'color'],
            ['tag' => 'grav-off--color'],
        ));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_theme_serves_fields_only(): void
    {
        $response = $this->controller()->moduleScript($this->request(
            'themes/quark/field/swatch',
            ['slug' => 'quark', 'type' => 'field', 'name' => 'swatch'],
            ['tag' => 'grav-quark--swatch'],
        ));
        self::assertSame(200, $response->getStatusCode());

        $this->expectException(NotFoundException::class);
        $this->controller()->moduleScript($this->request(
            'themes/quark/page',
            ['slug' => 'quark', 'type' => 'page'],
            ['tag' => 'grav-quark--page'],
        ));
    }

    #[Test]
    public function a_missing_module_is_a_404(): void
    {
        $this->expectException(NotFoundException::class);
        $this->controller()->moduleScript($this->request(
            'plugins/shop/report/nope',
            ['slug' => 'shop', 'type' => 'report', 'name' => 'nope'],
            ['tag' => 'grav-shop--nope'],
        ));
    }

    #[Test]
    public function version_for_is_null_when_there_is_no_file(): void
    {
        $this->controller();
        $grav = \Grav\Common\Grav::instance();
        self::assertNull(AdminModules::versionFor($grav, 'panel', 'shop'));
        self::assertNull(AdminModules::versionFor($grav, 'page', 'missing'));
        self::assertNull(AdminModules::versionFor($grav, 'field', 'shop', '../secret'));
        self::assertNotNull(AdminModules::versionFor($grav, 'field', 'quark', 'swatch', 'themes'));
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
            if ($item === '.' || $item === '..') continue;
            $this->rmrf($path . '/' . $item);
        }
        rmdir($path);
    }
}

final class ModuleScriptTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
    {
        foreach (['plugins://' => 'plugins', 'themes://' => 'themes', 'cache://' => 'cache'] as $scheme => $dir) {
            if (str_starts_with($uri, $scheme)) {
                $path = rtrim($this->base . '/' . $dir . '/' . substr($uri, strlen($scheme)), '/');

                return file_exists($path) ? $path : false;
            }
        }

        return false;
    }
}
