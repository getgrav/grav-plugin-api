<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for BlueprintController::serializeFields() leading-dot
 * resolution. A child keyed `.optionA` uses relative naming: it binds under
 * its container's own name rather than the (transparent) layout prefix, so a
 * field inside a section named `header.sectionName` resolves to the full
 * dotted name `header.sectionName.optionA` and saves nested. This restores the
 * Grav 1.x admin-classic behaviour that broke in Admin 2.0. See getgrav/grav#4120.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintLeadingDotTest extends TestCase
{
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
    public function leading_dot_child_resolves_against_its_section_name(): void
    {
        $fields = $this->serialize([
            'header.sectionName' => [
                'type' => 'section',
                'fields' => [
                    '.optionA' => ['type' => 'text'],
                    '.optionB' => ['type' => 'text'],
                ],
            ],
        ]);

        $children = $fields[0]['fields'];

        $this->assertSame('header.sectionName.optionA', $children[0]['name']);
        $this->assertSame('header.sectionName.optionB', $children[1]['name']);
    }

    #[Test]
    public function plain_child_of_a_section_stays_transparent(): void
    {
        // A section without dotted naming is purely visual: a plain child binds
        // to the top level, NOT namespaced under the section.
        $fields = $this->serialize([
            'mysection' => [
                'type' => 'section',
                'fields' => [
                    'optionA' => ['type' => 'text'],
                ],
            ],
        ]);

        $this->assertSame('optionA', $fields[0]['fields'][0]['name']);
    }

    #[Test]
    public function nested_sections_chain_the_resolved_name(): void
    {
        $fields = $this->serialize([
            'header.outer' => [
                'type' => 'section',
                'fields' => [
                    '.inner' => [
                        'type' => 'section',
                        'fields' => [
                            '.optionA' => ['type' => 'text'],
                        ],
                    ],
                ],
            ],
        ]);

        $inner = $fields[0]['fields'][0];
        $this->assertSame('header.outer.inner', $inner['name']);
        $this->assertSame('header.outer.inner.optionA', $inner['fields'][0]['name']);
    }

    #[Test]
    public function non_layout_container_prefixes_children_with_its_path(): void
    {
        // Regression guard: a non-layout container with nested fields keeps the
        // existing behaviour of prefixing plain children with its own path.
        $fields = $this->serialize([
            'parent' => [
                'type' => 'list',
                'fields' => [
                    'child' => ['type' => 'text'],
                ],
            ],
        ]);

        $this->assertSame('parent.child', $fields[0]['fields'][0]['name']);
    }

    #[Test]
    public function leading_dot_without_a_container_drops_the_dot(): void
    {
        // A leading-dot field with no parent context falls back to the plain
        // name (mirrors the form plugin's plain_name behaviour).
        $fields = $this->serialize([
            '.orphan' => ['type' => 'text'],
        ]);

        $this->assertSame('orphan', $fields[0]['name']);
    }
}
