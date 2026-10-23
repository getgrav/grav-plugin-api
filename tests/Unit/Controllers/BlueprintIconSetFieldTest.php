<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * An `iconpicker` field that names an icon set (`icons: encore`) carries the
 * set's icons to the admin, so the picker lists the theme's own icons rather
 * than Font Awesome.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintIconSetFieldTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $fields, int &$fired = 0): array
    {
        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();

        $language = new class {
            public function getLanguage(): string
            {
                return 'en';
            }

            /** @param array<int, string>|null $languages */
            public function translate(string $key, ?array $languages = null, bool $arraySupport = false): string
            {
                return $key;
            }
        };

        Grav::resetInstance();
        $grav = Grav::instance();
        unset($grav['language']);
        $grav['language'] = $language;
        $grav->addListener('onApiIconSets', function ($event) use (&$fired): void {
            $fired++;
            $event['sets'] = ['encore' => [
                'label' => 'Encore',
                'icons' => ['bag' => '<path d="M6 8h12l-1 12H7z"/>', 'star' => 'fa:star'],
            ]] + $event['sets'];
        });

        $ref->getProperty('grav')->setValue($instance, $grav);

        return $ref->getMethod('serializeFields')->invoke($instance, $fields);
    }

    #[Test]
    public function a_field_naming_a_set_carries_its_icons(): void
    {
        $fired = 0;
        $fields = $this->serialize([
            'header.hero.icon' => ['type' => 'iconpicker', 'label' => 'Icon', 'icons' => 'encore'],
            'header.facts.icon' => ['type' => 'iconpicker', 'label' => 'Icon', 'icons' => 'encore'],
        ], $fired);

        self::assertSame('encore', $fields[0]['icons']);
        self::assertSame('Encore', $fields[0]['icon_set']['label']);
        self::assertSame(['bag', 'star'], array_column($fields[0]['icon_set']['icons'], 'name'));
        self::assertSame('svg', $fields[0]['icon_set']['icons'][0]['icon']['type']);
        self::assertSame($fields[0]['icon_set'], $fields[1]['icon_set']);
        self::assertSame(1, $fired, 'the sets are asked for once, however many fields name one');
    }

    #[Test]
    public function an_unknown_set_is_named_without_icons_and_a_plain_picker_is_unchanged(): void
    {
        $fields = $this->serialize([
            'a' => ['type' => 'iconpicker', 'icons' => 'nobody'],
            'b' => ['type' => 'iconpicker'],
            'c' => ['type' => 'text', 'icons' => 'encore'],
        ]);

        self::assertSame('nobody', $fields[0]['icons']);
        self::assertArrayNotHasKey('icon_set', $fields[0]);
        self::assertArrayNotHasKey('icons', $fields[1]);
        self::assertArrayNotHasKey('icon_set', $fields[1]);
        self::assertArrayNotHasKey('icon_set', $fields[2], 'only the icon picker takes a set');
    }
}
