<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for BlueprintController::serializeFields() option normalization.
 *
 * With strict YAML (system.strict_mode.yaml_compat: false) Grav uses the native
 * YAML 1.1 parser, which reads unquoted Yes/No/On/Off option labels as booleans.
 * Those booleans must come back to the client as readable Yes/No so blueprints
 * authored for Grav 1.7's compat parser keep rendering correctly without the
 * author quoting every label. See getgrav/grav-plugin-admin2#36.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintBooleanOptionsTest extends TestCase
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
    public function boolean_option_labels_become_yes_no(): void
    {
        // What native YAML 1.1 produces from `'0': No` / `'1': Yes`.
        $fields = $this->serialize([
            'chromeless.enabled' => [
                'type' => 'toggle',
                'options' => ['0' => false, '1' => true],
                'validate' => ['type' => 'bool'],
            ],
        ]);

        $options = $fields[0]['options'];

        $this->assertSame([
            ['value' => '0', 'label' => 'No'],
            ['value' => '1', 'label' => 'Yes'],
        ], $options);
    }
}
