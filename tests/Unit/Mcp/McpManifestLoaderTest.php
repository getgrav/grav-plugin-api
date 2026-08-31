<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Mcp;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Mcp\McpManifestLoader;
use Grav\Plugin\Api\Mcp\McpToolCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RocketTheme\Toolbox\Event\Event;

/**
 * Covers reading `mcp.yaml` off disk for every enabled plugin: which plugins are
 * looked at, what a broken or unversioned manifest costs, the fingerprint, and
 * the onApiMcpTools hand-off that follows.
 *
 * Works against tests/Fixtures/mcp/plugins, which holds one manifest whose
 * entries between them break every validation rule.
 */
#[CoversClass(McpManifestLoader::class)]
#[CoversClass(McpToolCollector::class)]
class McpManifestLoaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/mcp/plugins';

    #[Test]
    public function every_enabled_plugins_manifest_is_read(): void
    {
        $tools = $this->load()->tools();

        self::assertSame([
            'shop_list_products',
            'shop_get_product',
            'shop_update_product',
            'shop_delete_product',
            'shop_create_product',
            'shop_storefront_status',
            'shop_list_bundles',
        ], array_column($tools, 'name'));
    }

    #[Test]
    public function a_disabled_plugin_is_not_read(): void
    {
        $collector = $this->load(['demo-shop' => ['enabled' => false]]);

        // Nothing of demo-shop's is left, and other-shop's own list_products is
        // no longer a duplicate now that nobody claimed the name first.
        self::assertSame(['shop_list_products', 'shop_list_bundles'], array_column($collector->tools(), 'name'));
        self::assertSame(['other-shop'], array_column($collector->plugins(), 'slug'));
    }

    #[Test]
    public function a_plugin_with_no_manifest_is_not_listed(): void
    {
        self::assertSame(
            ['demo-shop', 'other-shop'],
            array_column($this->load()->plugins(), 'slug'),
        );
    }

    #[Test]
    public function a_listed_plugin_carries_its_name_version_and_count(): void
    {
        self::assertSame([
            ['slug' => 'demo-shop', 'name' => 'Demo Shop', 'version' => '2.3.0', 'tools' => 6],
            ['slug' => 'other-shop', 'name' => 'Other Shop', 'version' => '0.9.1', 'tools' => 1],
        ], $this->load()->plugins());
    }

    #[Test]
    public function a_manifest_that_will_not_parse_costs_one_warning_and_nothing_else(): void
    {
        $warnings = $this->load()->warnings();

        self::assertSame(
            1,
            count(array_filter($warnings, static fn(string $w): bool => str_starts_with($w, 'broken-yaml: '))),
        );
        self::assertStringContainsString('mcp.yaml could not be parsed', $warnings[0]);
        // The rest of the plugins were still read.
        self::assertNotSame([], $this->load()->tools());
    }

    #[Test]
    public function a_manifest_without_a_version_is_skipped_whole(): void
    {
        $warnings = $this->load()->warnings();

        self::assertContains("no-version: mcp.yaml is missing 'version: 1'", $warnings);
        self::assertNotContains('nover_ping', array_column($this->load()->tools(), 'name'));
    }

    #[Test]
    public function every_invalid_entry_in_the_fixture_manifest_is_reported(): void
    {
        $warnings = $this->load()->warnings();

        self::assertContains("demo-shop: tool 'ListProducts' skipped: 'name' must match ^[a-z][a-z0-9_]*$", $warnings);
        self::assertContains(
            "demo-shop: tool 'shop_purge_products' skipped: 'method' must be one of GET, POST, PATCH, PUT, DELETE",
            $warnings,
        );
        self::assertContains(
            "demo-shop: tool 'shop_archive_product' skipped: path parameter 'id' is not declared in input.properties",
            $warnings,
        );
        self::assertContains(
            "demo-shop: tool 'shop_upload_image' skipped: unsupported schema keyword 'oneOf' at properties.file",
            $warnings,
        );
        self::assertContains(
            "demo-shop: tool 'shop_search_products' skipped: unsupported schema keyword 'patternProperties' at properties.filters.items",
            $warnings,
        );
        self::assertContains(
            "demo-shop: tool 'shop_bulk_publish' skipped: unknown annotation 'cacheable' (expected one of readOnly, destructive, idempotent)",
            $warnings,
        );
        self::assertContains(
            "other-shop: tool 'shop_list_products' skipped: duplicate tool name, already contributed by 'demo-shop'",
            $warnings,
        );

        $long = array_values(array_filter(
            $warnings,
            static fn(string $w): bool => str_contains($w, 'over the 64 character limit'),
        ));
        self::assertCount(1, $long);
    }

    #[Test]
    public function a_no_argument_tool_carries_the_empty_object_schema(): void
    {
        $tools = array_column($this->load()->tools(), null, 'name');

        self::assertStringContainsString(
            '"input_schema":{"type":"object","properties":{}}',
            (string) json_encode($tools['shop_storefront_status']),
        );
        self::assertSame([], $tools['shop_storefront_status']['path_params']);
    }

    #[Test]
    public function the_query_list_survives_the_round_trip_through_yaml(): void
    {
        $tools = array_column($this->load()->tools(), null, 'name');

        self::assertSame(['notify'], $tools['shop_update_product']['query']);
        self::assertSame(['id'], $tools['shop_update_product']['path_params']);
        self::assertSame(['title', 'id'], $tools['shop_update_product']['input_schema']['required']);
    }

    #[Test]
    public function the_fingerprint_is_stable_and_moves_with_the_enabled_set(): void
    {
        $loader = $this->loader();
        $loader->load();
        $first = $loader->fingerprint();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);

        $again = $this->loader();
        $again->load();
        self::assertSame($first, $again->fingerprint());

        $fewer = $this->loader(['quiet' => ['enabled' => false]]);
        $fewer->load();
        self::assertNotSame($first, $fewer->fingerprint());
    }

    #[Test]
    public function the_fingerprint_moves_when_a_manifest_is_edited(): void
    {
        $dir = sys_get_temp_dir() . '/grav_api_mcp_' . uniqid();
        mkdir($dir . '/demo-shop', 0775, true);
        copy(self::FIXTURES . '/demo-shop/mcp.yaml', $dir . '/demo-shop/mcp.yaml');

        $loader = $this->loader(['demo-shop' => ['enabled' => true]], $dir);
        $loader->load();
        $before = $loader->fingerprint();

        touch($dir . '/demo-shop/mcp.yaml', time() + 30);

        $after = $this->loader(['demo-shop' => ['enabled' => true]], $dir);
        $after->load();

        self::assertNotSame($before, $after->fingerprint());

        unlink($dir . '/demo-shop/mcp.yaml');
        rmdir($dir . '/demo-shop');
        rmdir($dir);
    }

    #[Test]
    public function the_event_fires_after_the_files_are_read_and_can_add_tools(): void
    {
        $collector = $this->load(listener: static function (Event $event): void {
            $event['tools']->add('demo-shop', [
                'name' => 'sync_stripe',
                'description' => 'Re-sync products and prices with the payment provider.',
                'method' => 'POST',
                'path' => '/demo-shop/providers/stripe/sync',
                'permission' => 'demoshop.settings',
            ]);
            // The manifest got there first, so this one loses.
            $event['tools']->add('demo-shop', [
                'name' => 'list_products',
                'description' => 'Duplicate of a name the manifest already claimed.',
                'method' => 'GET',
                'path' => '/demo-shop/products',
            ]);
        });

        $names = array_column($collector->tools(), 'name');
        self::assertContains('shop_sync_stripe', $names);
        // The event's tool keeps the prefix the manifest asked for.
        self::assertNotContains('demo_shop_sync_stripe', $names);
        self::assertContains(
            "demo-shop: tool 'shop_list_products' skipped: duplicate tool name, already contributed by 'demo-shop'",
            $collector->warnings(),
        );
        self::assertSame(7, array_column($collector->plugins(), 'tools', 'slug')['demo-shop']);
    }

    /**
     * @param array<string, mixed> $pluginOverrides
     */
    private function load(array $pluginOverrides = [], ?callable $listener = null): McpToolCollector
    {
        return $this->loader($pluginOverrides, null, $listener)->load();
    }

    /**
     * @param array<string, mixed> $pluginOverrides
     */
    private function loader(array $pluginOverrides = [], ?string $root = null, ?callable $listener = null): McpManifestLoader
    {
        $plugins = array_merge([
            'api' => ['enabled' => true],
            'broken-yaml' => ['enabled' => true],
            'demo-shop' => ['enabled' => true],
            'no-version' => ['enabled' => true],
            'other-shop' => ['enabled' => true],
            'quiet' => ['enabled' => true],
            'switched-off' => ['enabled' => false],
        ], $pluginOverrides);

        $locator = new McpTestLocator($root ?? self::FIXTURES);

        $grav = $this->createMock(Grav::class);
        $grav->method('offsetGet')->willReturnCallback(
            static fn($key) => $key === 'locator' ? $locator : null,
        );
        $grav->method('fireEvent')->willReturnCallback(
            static function ($name, $event) use ($listener) {
                if ($listener !== null && $name === 'onApiMcpTools') {
                    $listener($event);
                }

                return $event;
            },
        );

        return new McpManifestLoader($grav, new Config(['plugins' => $plugins]));
    }
}

/**
 * Resolves plugins:// into the fixture tree and nothing else, so the loader can
 * be pointed at a handful of made-up plugins.
 */
final class McpTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        if (!str_starts_with($uri, 'plugins://')) {
            return false;
        }

        $path = $this->base . '/' . substr($uri, strlen('plugins://'));

        return is_file($path) ? $path : false;
    }
}
