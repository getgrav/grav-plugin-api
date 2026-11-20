<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Data\Blueprint;
use Grav\Plugin\Api\Controllers\BlueprintController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/** Stands in for core's Pages::pageTypes(); the serializer keys its special case on the method name. */
class HideListTemplates
{
    /** @return array<string, string> */
    public static function pageTypes(string $type = 'standard'): array
    {
        return $type === 'modular'
            ? ['modular/hero' => 'Hero', 'modular/text' => 'Text']
            : ['default' => 'Default', 'blog' => 'Blog', 'item' => 'Item'];
    }
}

/**
 * The Template select on a page's Advanced tab is baked into the serialized
 * blueprint from `data-options@: Pages::pageTypes`. It has to honour
 * plugins.admin.hide_page_types like Add Page does (getgrav/grav-plugin-admin#2244).
 */
#[CoversClass(BlueprintController::class)]
class BlueprintTemplateHideListTest extends TestCase
{
    private const PROVIDER = HideListTemplates::class . '::pageTypes';

    /** @return list<string> */
    private function templateOptions(string $blueprintName, array $config): array
    {
        Blueprint::addAllowedDynamicCallable(self::PROVIDER);

        $grav = TestHelper::createMockGrav([
            'events' => new EventDispatcher(),
            'config' => new class ($config) {
                public function __construct(private readonly array $values) {}
                public function get(string $key, mixed $default = null): mixed
                {
                    return $this->values[$key] ?? $default;
                }
            },
        ]);

        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('grav')->setValue($instance, $grav);
        $ref->getProperty('pageTypeContext')->setValue(
            $instance,
            str_starts_with($blueprintName, 'modular/') ? 'modular' : 'standard'
        );

        $fields = $ref->getMethod('serializeFields')->invoke($instance, [
            'name' => ['type' => 'select', 'data-options@' => '\\' . self::PROVIDER],
        ]);

        return array_column($fields[0]['options'] ?? [], 'value');
    }

    #[Test]
    public function hidden_page_templates_are_left_out(): void
    {
        $this->assertSame(
            ['default', 'blog'],
            $this->templateOptions('default', ['plugins.admin.hide_page_types' => ['item']])
        );
    }

    #[Test]
    public function hidden_module_templates_are_left_out(): void
    {
        $this->assertSame(
            ['modular/hero'],
            $this->templateOptions('modular/hero', ['plugins.admin.hide_modular_page_types' => ['modular/text']])
        );
    }
}
