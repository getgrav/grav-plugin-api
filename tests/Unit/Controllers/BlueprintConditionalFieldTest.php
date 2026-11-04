<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\BlueprintController;
use Grav\Plugin\Api\Services\BlueprintLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A `conditional` only decides whether its fields show. Like a section or
 * columns, it's a wrapper: its fields keep their own names, as in the form
 * plugin. It used to prefix them with its own name, so Admin2 showed them
 * empty and saved them under the wrong key (grav-plugin-api#75).
 */
#[CoversClass(BlueprintController::class)]
#[CoversClass(BlueprintLoader::class)]
class BlueprintConditionalFieldTest extends TestCase
{
    /** The blueprint from the report, with a columns block inside the conditional. */
    private const FIELDS = [
        'header.event.location' => ['type' => 'text'],
        'location_venue' => [
            'type' => 'conditional',
            'condition' => "header.event.location == 'venue'",
            'fields' => [
                'header.event.venue' => ['type' => 'text'],
                'venue_columns' => [
                    'type' => 'columns',
                    'fields' => [
                        'left' => [
                            'type' => 'column',
                            'fields' => [
                                'header.event.lat' => ['type' => 'text'],
                                'header.event.map' => ['type' => 'file'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $fields): array
    {
        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();

        return $ref->getMethod('serializeFields')->invoke($instance, $fields);
    }

    #[Test]
    public function fields_inside_a_conditional_keep_their_own_names(): void
    {
        $conditional = $this->serialize(self::FIELDS)[1];

        $this->assertSame('location_venue', $conditional['name']);
        $this->assertSame('conditional', $conditional['type']);
        $this->assertSame('header.event.venue', $conditional['fields'][0]['name']);

        $column = $conditional['fields'][1]['fields'][0];
        $this->assertSame('header.event.lat', $column['fields'][0]['name']);
    }

    #[Test]
    public function a_leading_dot_child_still_binds_under_the_conditional(): void
    {
        $fields = $this->serialize([
            'header.extra' => [
                'type' => 'conditional',
                'condition' => 'true',
                'fields' => ['.note' => ['type' => 'text']],
            ],
        ]);

        $this->assertSame('header.extra.note', $fields[0]['fields'][0]['name']);
    }

    #[Test]
    public function the_upload_check_finds_a_file_field_inside_a_conditional(): void
    {
        $field = BlueprintLoader::findField(self::FIELDS, 'header.event.map');

        $this->assertNotNull($field);
        $this->assertSame('file', $field['type']);
        $this->assertNull(BlueprintLoader::findField(self::FIELDS, 'location_venue.header.event.map'));
    }
}
