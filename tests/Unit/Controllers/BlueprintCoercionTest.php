<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Controllers\ConfigController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit coverage for AbstractApiController::coerceForValidation() — the bool↔int
 * leniency that lets blueprint validation accept Grav's own loosely-typed
 * values (e.g. system.errors.display ships as a bool against a `type: int`
 * rule; Grav's runtime treats true/false as 1/0). See getgrav/grav-plugin-admin2#30.
 */
#[CoversClass(AbstractApiController::class)]
class BlueprintCoercionTest extends TestCase
{
    private function coerce(mixed $value, array $field): mixed
    {
        $ref = new ReflectionClass(AbstractApiController::class);
        $instance = (new ReflectionClass(ConfigController::class))->newInstanceWithoutConstructor();

        return $ref->getMethod('coerceForValidation')->invoke($instance, $value, $field);
    }

    public static function intTypedFields(): array
    {
        return [
            'validate.type int'     => [['type' => 'select', 'validate' => ['type' => 'int']]],
            'validate.type number'  => [['type' => 'text', 'validate' => ['type' => 'number']]],
            'field type number'     => [['type' => 'number']],
        ];
    }

    #[Test]
    #[DataProvider('intTypedFields')]
    public function booleans_become_ints_for_int_typed_fields(array $field): void
    {
        $this->assertSame(1, $this->coerce(true, $field));
        $this->assertSame(0, $this->coerce(false, $field));
    }

    #[Test]
    public function int_typed_field_leaves_non_booleans_untouched(): void
    {
        $field = ['type' => 'select', 'validate' => ['type' => 'int']];
        $this->assertSame(1, $this->coerce(1, $field));
        $this->assertSame('1', $this->coerce('1', $field));
        $this->assertSame(-1, $this->coerce(-1, $field));
    }

    #[Test]
    public function non_int_fields_leave_booleans_untouched(): void
    {
        // A genuine boolean field (toggle/bool) must keep its bool — only
        // int/number-typed fields get the leniency.
        $this->assertTrue($this->coerce(true, ['type' => 'toggle', 'validate' => ['type' => 'bool']]));
        $this->assertSame('text', $this->coerce('text', ['type' => 'text']));
        $this->assertTrue($this->coerce(true, ['type' => 'text']));
    }
}
