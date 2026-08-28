<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Mcp;

use Grav\Plugin\Api\Mcp\McpToolCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the validation, normalization and dedupe rules every MCP tool goes
 * through, whether it arrived from a manifest file or from onApiMcpTools.
 */
#[CoversClass(McpToolCollector::class)]
class McpToolCollectorTest extends TestCase
{
    #[Test]
    public function a_valid_tool_is_normalized_into_the_documented_output_fields(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo-shop', [
            'name' => 'list_products',
            'title' => 'List products',
            'description' => "  List catalog products.  ",
            'method' => 'GET',
            'path' => '/demo-shop/products',
            'permission' => 'demoshop.products.read',
            'input' => [
                'type' => 'object',
                'properties' => ['q' => ['type' => 'string']],
            ],
        ]);

        self::assertSame([], $collector->warnings());
        self::assertSame([
            'name' => 'demo_shop_list_products',
            'plugin' => 'demo-shop',
            'title' => 'List products',
            'description' => 'List catalog products.',
            'method' => 'GET',
            'path' => '/demo-shop/products',
            'permission' => 'demoshop.products.read',
            'annotations' => ['readOnly' => true, 'destructive' => false, 'idempotent' => true],
            'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
            'path_params' => [],
            'query' => [],
        ], $this->roundTrip($collector->tools()[0]));
    }

    #[Test]
    public function the_slugs_dashes_become_underscores_in_the_default_prefix(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo-shop', $this->tool());

        self::assertSame('demo_shop_ping', $collector->tools()[0]['name']);
    }

    #[Test]
    public function a_manifest_prefix_replaces_the_slug_default(): void
    {
        $collector = new McpToolCollector();
        $collector->registerPlugin('demo-shop', 'shop');
        $collector->add('demo-shop', $this->tool());

        self::assertSame('shop_ping', $collector->tools()[0]['name']);
    }

    /**
     * @param array<string, bool> $expected
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('annotationDefaults')]
    public function annotations_default_by_method(string $method, array $expected): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['method' => $method] + $this->tool());

        self::assertSame($expected, $collector->tools()[0]['annotations']);
    }

    /**
     * @return array<string, array{0: string, 1: array<string, bool>}>
     */
    public static function annotationDefaults(): array
    {
        return [
            'GET is a safe read' => ['GET', ['readOnly' => true, 'destructive' => false, 'idempotent' => true]],
            'POST is none of them' => ['POST', ['readOnly' => false, 'destructive' => false, 'idempotent' => false]],
            'PUT is idempotent' => ['PUT', ['readOnly' => false, 'destructive' => false, 'idempotent' => true]],
            'PATCH is idempotent' => ['PATCH', ['readOnly' => false, 'destructive' => false, 'idempotent' => true]],
            'DELETE destroys' => ['DELETE', ['readOnly' => false, 'destructive' => true, 'idempotent' => true]],
        ];
    }

    #[Test]
    public function an_annotation_overrides_only_the_key_it_names(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'method' => 'POST',
            'annotations' => ['idempotent' => true],
        ] + $this->tool());

        self::assertSame(
            ['readOnly' => false, 'destructive' => false, 'idempotent' => true],
            $collector->tools()[0]['annotations'],
        );
    }

    #[Test]
    public function an_unknown_annotation_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['annotations' => ['cacheable' => true]] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString("unknown annotation 'cacheable'", $collector->warnings()[0]);
    }

    #[Test]
    public function a_tool_with_no_input_gets_the_empty_object_schema(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', $this->tool());

        self::assertSame(
            ['type' => 'object', 'properties' => []],
            $this->roundTrip($collector->tools()[0])['input_schema'],
        );
        self::assertStringContainsString(
            '"input_schema":{"type":"object","properties":{}}',
            json_encode($collector->tools()[0]),
        );
    }

    #[Test]
    public function path_placeholders_become_path_params_and_are_required(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'method' => 'PATCH',
            'path' => '/demo/stores/{store}/products/{id}',
            'input' => [
                'type' => 'object',
                'properties' => [
                    'store' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                ],
                'required' => ['title'],
            ],
        ] + $this->tool());

        $tool = $collector->tools()[0];
        self::assertSame(['store', 'id'], $tool['path_params']);
        self::assertSame(['title', 'store', 'id'], $tool['input_schema']['required']);
    }

    #[Test]
    public function a_path_placeholder_missing_from_properties_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'path' => '/demo/products/{id}/archive',
            'input' => ['type' => 'object', 'properties' => ['reason' => ['type' => 'string']]],
        ] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertSame(
            "demo: tool 'demo_ping' skipped: path parameter 'id' is not declared in input.properties",
            $collector->warnings()[0],
        );
    }

    #[Test]
    public function a_regex_constrained_placeholder_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['path' => '/demo/products/{id:\d+}'] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString('must be a bare parameter name', $collector->warnings()[0]);
    }

    #[Test]
    public function the_query_list_is_kept_when_every_name_is_a_declared_property(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'method' => 'PATCH',
            'path' => '/demo/products/{id}',
            'query' => ['notify'],
            'input' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'notify' => ['type' => 'boolean'],
                ],
            ],
        ] + $this->tool());

        self::assertSame(['notify'], $collector->tools()[0]['query']);
    }

    #[Test]
    public function a_query_name_that_is_not_a_property_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['method' => 'POST', 'query' => ['notify']] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString(
            "query parameter 'notify' is not declared in input.properties",
            $collector->warnings()[0],
        );
    }

    #[Test]
    public function a_name_outside_the_pattern_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['name' => 'ListProducts'] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString('must match ^[a-z][a-z0-9_]*$', $collector->warnings()[0]);
    }

    #[Test]
    public function a_prefixed_name_over_sixty_four_characters_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['name' => str_repeat('a', 60)] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString('over the 64 character limit', $collector->warnings()[0]);
    }

    #[Test]
    public function a_name_of_exactly_sixty_four_characters_is_kept(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['name' => str_repeat('a', 59)] + $this->tool());

        self::assertSame([], $collector->warnings());
        self::assertSame(64, strlen($collector->tools()[0]['name']));
    }

    #[Test]
    public function an_unsupported_method_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', ['method' => 'PURGE'] + $this->tool());

        self::assertSame([], $collector->tools());
        self::assertStringContainsString("'method' must be one of GET, POST, PATCH, PUT, DELETE", $collector->warnings()[0]);
    }

    #[Test]
    public function a_missing_description_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $tool = $this->tool();
        unset($tool['description']);
        $collector->add('demo', $tool);

        self::assertSame([], $collector->tools());
        self::assertStringContainsString("'description' is required", $collector->warnings()[0]);
    }

    #[Test]
    public function an_unsupported_schema_keyword_is_named_with_its_path(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'input' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['oneOf' => [['type' => 'string'], ['type' => 'object']]],
                ],
            ],
        ] + $this->tool());

        self::assertSame(
            "demo: tool 'demo_ping' skipped: unsupported schema keyword 'oneOf' at properties.file",
            $collector->warnings()[0],
        );
    }

    #[Test]
    public function an_unsupported_keyword_nested_under_array_items_is_found(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'input' => [
                'type' => 'object',
                'properties' => [
                    'filters' => [
                        'type' => 'array',
                        'items' => ['type' => 'object', 'patternProperties' => ['^x-' => ['type' => 'string']]],
                    ],
                ],
            ],
        ] + $this->tool());

        self::assertSame(
            "demo: tool 'demo_ping' skipped: unsupported schema keyword 'patternProperties' at properties.filters.items",
            $collector->warnings()[0],
        );
    }

    #[Test]
    public function a_tuple_items_definition_is_rejected(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'input' => [
                'type' => 'object',
                'properties' => [
                    'pair' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]],
                ],
            ],
        ] + $this->tool());

        self::assertStringContainsString('must be a single schema, not a tuple', $collector->warnings()[0]);
    }

    #[Test]
    public function an_object_with_no_properties_becomes_a_free_form_map(): void
    {
        $collector = new McpToolCollector();
        $collector->add('demo', [
            'input' => [
                'type' => 'object',
                'properties' => ['attributes' => ['type' => 'object', 'description' => 'slug to value']],
            ],
        ] + $this->tool());

        self::assertTrue($collector->tools()[0]['input_schema']['properties']['attributes']['additionalProperties']);
    }

    #[Test]
    public function the_first_plugin_to_claim_a_name_keeps_it(): void
    {
        $collector = new McpToolCollector();
        $collector->registerPlugin('demo-shop', 'shop');
        $collector->registerPlugin('other-shop', 'shop');
        $collector->add('demo-shop', ['name' => 'list_products'] + $this->tool());
        $collector->add('other-shop', ['name' => 'list_products'] + $this->tool());

        self::assertCount(1, $collector->tools());
        self::assertSame('demo-shop', $collector->tools()[0]['plugin']);
        self::assertSame(
            "other-shop: tool 'shop_list_products' skipped: duplicate tool name, already contributed by 'demo-shop'",
            $collector->warnings()[0],
        );
    }

    #[Test]
    public function plugins_carry_their_metadata_and_a_tool_count(): void
    {
        $collector = new McpToolCollector(static fn(string $slug): array => match ($slug) {
            'demo-shop' => ['name' => 'Demo Shop', 'version' => '2.3.0'],
            default => ['name' => null, 'version' => null],
        });
        $collector->registerPlugin('demo-shop');
        $collector->registerPlugin('quiet');
        $collector->add('demo-shop', $this->tool());

        self::assertSame([
            ['slug' => 'demo-shop', 'name' => 'Demo Shop', 'version' => '2.3.0', 'tools' => 1],
            ['slug' => 'quiet', 'name' => null, 'version' => null, 'tools' => 0],
        ], $collector->plugins());
    }

    /**
     * A minimal valid tool that individual tests override one key of.
     *
     * @return array<string, mixed>
     */
    private function tool(): array
    {
        return [
            'name' => 'ping',
            'description' => 'Say whether the service answers.',
            'method' => 'GET',
            'path' => '/demo/ping',
        ];
    }

    /**
     * Encode and decode a tool so empty maps show up the way a client sees them.
     *
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private function roundTrip(array $tool): array
    {
        return json_decode((string) json_encode($tool), true);
    }
}
