<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Data\Blueprint;
use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Provider for the `data-fields@` cases below. Registered on core's dynamic
 * callable allowlist by the test that needs it.
 */
class DynamicFieldsProvider
{
    /** @return array<string, array<string, string>> */
    public static function widths(): array
    {
        return [
            'cap_sm' => ['type' => 'text'],
            'cap_lg' => ['type' => 'text'],
        ];
    }
}

/**
 * Unit coverage for dynamic field resolution in BlueprintController
 * (getgrav/grav-plugin-api#21).
 *
 * A container that builds its children with `data-fields@` used to reach
 * admin-next empty: config-family blueprints were loaded without
 * `Blueprint::init()` — the step that applies `data-*@` directives — and the
 * serializer only ever expanded `data-options@`. Both halves are covered here.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintDynamicFieldsTest extends TestCase
{
    private const PROVIDER = DynamicFieldsProvider::class . '::widths';

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
    public function data_fields_directive_fills_an_empty_container(): void
    {
        Blueprint::addAllowedDynamicCallable(self::PROVIDER);

        $fields = $this->serialize([
            'caps' => [
                'type' => 'fieldset',
                'fields' => [],
                'data-fields@' => '\\' . self::PROVIDER,
            ],
        ]);

        $children = $fields[0]['fields'] ?? [];

        $this->assertCount(2, $children);
        $this->assertSame('cap_sm', $children[0]['name']);
        $this->assertSame('cap_lg', $children[1]['name']);
    }

    #[Test]
    public function data_fields_directive_is_refused_when_the_provider_is_not_allowlisted(): void
    {
        // Same gate core applies in Blueprint::dynamicData(): a provider that
        // hasn't opted in via addAllowedDynamicCallable() never runs.
        $fields = $this->serialize([
            'caps' => [
                'type' => 'fieldset',
                'fields' => [],
                'data-fields@' => '\Grav\Plugin\Api\Tests\NotRegistered::provider',
            ],
        ]);

        $this->assertSame([], $fields[0]['fields']);
    }

    #[Test]
    public function children_already_resolved_by_blueprint_init_are_left_alone(): void
    {
        // Blueprint::init() materializes the directive before the serializer
        // sees it. The serializer's fallback must not run a second time and
        // duplicate (or reorder) what is already there.
        Blueprint::addAllowedDynamicCallable(self::PROVIDER);

        $fields = $this->serialize([
            'caps' => [
                'type' => 'fieldset',
                'fields' => [
                    'preset' => ['type' => 'text'],
                ],
                'data-fields@' => '\\' . self::PROVIDER,
            ],
        ]);

        $children = $fields[0]['fields'] ?? [];

        $this->assertCount(1, $children);
        $this->assertSame('preset', $children[0]['name']);
    }

    #[Test]
    public function blueprint_init_resolves_data_fields_for_a_config_family_blueprint(): void
    {
        // The other half of the bug: what loadConfigBlueprint() now restores.
        Blueprint::addAllowedDynamicCallable(self::PROVIDER);

        $items = [
            'form' => [
                'fields' => [
                    'caps' => [
                        'type' => 'fieldset',
                        'fields' => [],
                        'data-fields@' => '\\' . self::PROVIDER,
                    ],
                ],
            ],
        ];

        $loadOnly = new Blueprint('test', $items);
        $loadOnly->load();
        $this->assertSame([], $loadOnly->fields()['caps']['fields']);

        $initialized = new Blueprint('test', $items);
        $initialized->load()->init();
        $this->assertSame(
            ['cap_sm', 'cap_lg'],
            array_keys($initialized->fields()['caps']['fields'])
        );
    }

    #[Test]
    public function guest_induced_security_gate_ignores_are_cleared(): void
    {
        // Core evaluates `security@` against $grav['user'] — the guest during a
        // token-authenticated API request — so the gate always fails and stamps
        // the subtree. Only gated subtrees get cleared; a hand-authored
        // `validate: ignore` elsewhere survives.
        $blueprint = new Blueprint('test', [
            'form' => [
                'fields' => [
                    'security' => [
                        'type' => 'section',
                        'security@' => 'admin.super',
                        'validate' => ['ignore' => true],
                        'fields' => [
                            'access' => [
                                'type' => 'permissions',
                                'validate' => ['type' => 'array', 'ignore' => true],
                            ],
                        ],
                    ],
                    'notes' => [
                        'type' => 'text',
                        'validate' => ['ignore' => true],
                    ],
                ],
            ],
        ]);

        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $ref->getMethod('clearGatedIgnores')->invoke($instance, $blueprint);

        $fields = $blueprint->fields();

        $this->assertArrayNotHasKey('validate', $fields['security']);
        $this->assertSame(['type' => 'array'], $fields['security']['fields']['access']['validate']);
        $this->assertSame(['ignore' => true], $fields['notes']['validate']);
    }
}
