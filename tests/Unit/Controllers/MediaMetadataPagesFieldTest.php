<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The `pages` type of the configurable `media_metadata.fields`: a list of page
 * routes, stored as a YAML sequence like `tags` and filtered the same way, so a
 * file in a central downloads folder can be tied to the pages that use it
 * (getgrav/grav-plugin-api#80).
 */
#[CoversClass(MediaController::class)]
class MediaMetadataPagesFieldTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_meta_pages_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function controller(): MediaController
    {
        $config = new Config(['plugins' => ['api' => ['media_metadata' => ['fields' => [
            ['key' => 'pages', 'label' => 'Pages', 'type' => 'pages'],
        ]]]]]);
        TestHelper::createMockGrav(['config' => $config]);

        return new MediaController(Grav::instance(), $config);
    }

    private function call(MediaController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($controller, $method))->invoke($controller, ...$args);
    }

    #[Test]
    public function pages_is_a_known_type(): void
    {
        $defs = $this->call($this->controller(), 'getMetadataFieldDefs');
        $pages = array_values(array_filter($defs, static fn($d) => $d['key'] === 'pages'));

        $this->assertSame('pages', $pages[0]['type']);
    }

    #[Test]
    public function routes_are_normalized_and_deduplicated(): void
    {
        $changes = $this->call($this->controller(), 'sanitizeMetaInput', [
            'pages' => ['/services', 'about-us', '/contact/', '/services', '  /blog/my-post  ', '', '/'],
        ]);

        $this->assertSame(['/services', '/about-us', '/contact', '/blog/my-post', '/'], $changes['pages']);
    }

    #[Test]
    public function a_comma_separated_string_is_accepted(): void
    {
        $changes = $this->call($this->controller(), 'sanitizeMetaInput', ['pages' => '/services, /about-us']);

        $this->assertSame(['/services', '/about-us'], $changes['pages']);
    }

    #[Test]
    public function an_empty_list_removes_the_key(): void
    {
        $changes = $this->call($this->controller(), 'sanitizeMetaInput', ['pages' => []]);

        $this->assertArrayHasKey('pages', $changes);
        $this->assertNull($changes['pages']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function notRoutes(): array
    {
        return [
            'markup' => [['/<script>alert(1)</script>']],
            'scheme' => [['javascript:alert(1)']],
            'url' => [['https://example.com/page']],
            'spaces' => [['/my page']],
            'parent' => [['/../user/accounts']],
            'double slash' => [['//example.com']],
            'not a string' => [[['/nested']]],
            'not a list' => [42],
        ];
    }

    #[Test]
    #[DataProvider('notRoutes')]
    public function anything_but_a_route_is_refused(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        $this->call($this->controller(), 'sanitizeMetaInput', ['pages' => $value]);
    }

    #[Test]
    public function the_sidecar_holds_a_yaml_list_and_reads_back(): void
    {
        $controller = $this->controller();
        $file = $this->dir . '/price-list.pdf';
        file_put_contents($file, '%PDF-1.4');

        $changes = $this->call($controller, 'sanitizeMetaInput', ['pages' => ['/services', '/about-us']]);
        $this->call($controller, 'applyMetaChanges', $file, $changes);

        $this->assertSame("pages:\n  - /services\n  - /about-us\n", file_get_contents($file . '.meta.yaml'));

        $response = $this->call($controller, 'buildMetaResponse', $file, 'price-list.pdf');
        $field = array_values(array_filter($response['fields'], static fn($f) => $f['key'] === 'pages'))[0];
        $this->assertSame('pages', $field['type']);
        $this->assertSame(['/services', '/about-us'], $field['value']);
    }

    #[Test]
    public function the_filter_treats_pages_as_a_list(): void
    {
        $controller = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
        $parse = static fn(string $clause) => (new ReflectionMethod(MediaController::class, 'parseFilterClause'))
            ->invoke($controller, $clause, ['pages' => 'pages'], 2000);

        $this->assertSame(['pages', 'contains', '/services'], $parse('pages:/services/'));
        $this->assertSame(['pages', 'in', ['/services', '/about-us']], $parse('pages:in:/services,about-us'));

        $this->expectException(ValidationException::class);
        $parse('pages:==:/services');
    }
}
