<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for BlueprintController::addTwigOptionToProcessField() — the
 * field-tree walker that restores the Twig checkbox in a page's
 * `header.process` field for an API user allowed to enable Twig in content.
 *
 * Core's Security::pageProcessOptions() drops Twig for token-authenticated
 * (guest $grav['user']) API/Admin-Next users even when they could save it,
 * leaving the editor showing only Markdown. The controller re-adds the option
 * against the same authority the write guard enforces. See grav-admin-next#5.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintTwigProcessOptionTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array{bool, array<int, array<string, mixed>>}
     */
    private function addTwig(array $fields): array
    {
        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('addTwigOptionToProcessField');

        $found = $method->invokeArgs($instance, [&$fields]);

        return [$found, $fields];
    }

    /**
     * Find the header.process field anywhere in a serialized tree.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, mixed>|null
     */
    private function findProcess(array $fields): ?array
    {
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === 'header.process') {
                return $field;
            }
            if (isset($field['fields']) && is_array($field['fields'])) {
                $hit = $this->findProcess($field['fields']);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    #[Test]
    public function appends_twig_when_only_markdown_is_listed(): void
    {
        [$found, $fields] = $this->addTwig([
            ['name' => 'header.process', 'type' => 'checkboxes', 'options' => [
                ['value' => 'markdown', 'label' => 'Markdown'],
            ]],
        ]);

        $this->assertTrue($found);
        $values = array_column($this->findProcess($fields)['options'], 'value');
        $this->assertSame(['markdown', 'twig'], $values);
    }

    #[Test]
    public function is_idempotent_when_twig_already_present(): void
    {
        [$found, $fields] = $this->addTwig([
            ['name' => 'header.process', 'type' => 'checkboxes', 'options' => [
                ['value' => 'markdown', 'label' => 'Markdown'],
                ['value' => 'twig', 'label' => 'Twig'],
            ]],
        ]);

        $this->assertTrue($found);
        $values = array_column($this->findProcess($fields)['options'], 'value');
        $this->assertSame(['markdown', 'twig'], $values, 'Twig must not be duplicated.');
    }

    #[Test]
    public function finds_the_field_nested_under_layout_containers(): void
    {
        // Real page blueprints wrap header.process under tabs > tab (Options).
        [$found, $fields] = $this->addTwig([
            ['name' => 'tabs', 'type' => 'tabs', 'fields' => [
                ['name' => 'options', 'type' => 'tab', 'fields' => [
                    ['name' => 'header.process', 'type' => 'checkboxes', 'options' => [
                        ['value' => 'markdown', 'label' => 'Markdown'],
                    ]],
                ]],
            ]],
        ]);

        $this->assertTrue($found);
        $values = array_column($this->findProcess($fields)['options'], 'value');
        $this->assertContains('twig', $values);
    }

    #[Test]
    public function seeds_options_when_the_field_has_none(): void
    {
        [$found, $fields] = $this->addTwig([
            ['name' => 'header.process', 'type' => 'checkboxes'],
        ]);

        $this->assertTrue($found);
        $this->assertSame(
            [['value' => 'twig', 'label' => 'Twig']],
            $this->findProcess($fields)['options']
        );
    }

    #[Test]
    public function leaves_other_fields_untouched_and_reports_not_found(): void
    {
        $input = [
            ['name' => 'header.title', 'type' => 'text'],
            ['name' => 'header.routable', 'type' => 'toggle', 'options' => [
                ['value' => '1', 'label' => 'Yes'],
            ]],
        ];

        [$found, $fields] = $this->addTwig($input);

        $this->assertFalse($found);
        $this->assertSame($input, $fields, 'Unrelated fields must be unchanged.');
    }
}
