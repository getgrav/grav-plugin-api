<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\BlueprintController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The documented "common field attributes" must survive serialization
 * (getgrav/grav-admin-next#18).
 *
 * `serializeFields()` copies field properties through a fixed allowlist rather
 * than wholesale, and `sublabel`, `labelclasses`, `display_label` and
 * `outerclasses` were simply never added to it. They were dropped server-side,
 * so no amount of frontend work could render them — and Grav's own
 * `blueprints/user/account.yaml` uses two of them. This pins the whole
 * documented set so the next addition to that list can't quietly lose one.
 */
#[CoversClass(BlueprintController::class)]
class BlueprintCommonAttributesTest extends TestCase
{
    /**
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function serialize(array $fields): array
    {
        $ref = new ReflectionClass(BlueprintController::class);
        $instance = $ref->newInstanceWithoutConstructor();

        // `label`/`sublabel`/`description` are run through translateLabel(),
        // which reads $grav['language'] before deciding whether the value even
        // looks like a translation key. The values used here are plain prose, so
        // only getLanguage() is reached and a minimal stub is enough — but the
        // property is typed to Grav, so it has to be the real container with the
        // language service swapped out.
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

        $grav = Grav::instance();
        unset($grav['language']);
        $grav['language'] = $language;

        (new \ReflectionProperty(\Grav\Plugin\Api\Controllers\AbstractApiController::class, 'grav'))->setValue($instance, $grav);

        return $ref->getMethod('serializeFields')->invoke($instance, $fields);
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function commonAttributeProvider(): array
    {
        return [
            'description' => ['description', 'Some description'],
            'sublabel' => ['sublabel', 'Some sublabel'],
            'sublabelclasses' => ['sublabelclasses', 'my-sublabel-class'],
            'labelclasses' => ['labelclasses', 'my-label-class'],
            'outerclasses' => ['outerclasses', 'my-outer-class'],
            'display_label' => ['display_label', false],
            'autocomplete' => ['autocomplete', 'off'],
            'autofocus' => ['autofocus', true],
            'novalidate' => ['novalidate', true],
        ];
    }

    #[Test]
    #[DataProvider('commonAttributeProvider')]
    public function common_field_attributes_reach_the_browser(string $attribute, mixed $value): void
    {
        $fields = $this->serialize([
            'custom_logo' => [
                'type' => 'file',
                'label' => 'Some label',
                $attribute => $value,
            ],
        ]);

        $this->assertArrayHasKey(
            $attribute,
            $fields[0],
            sprintf('"%s" is a documented common attribute and must not be dropped by the allowlist.', $attribute)
        );
        $this->assertSame($value, $fields[0][$attribute]);
    }

    #[Test]
    public function a_fieldset_icon_reaches_the_browser(): void
    {
        // Classic admin's fieldset template draws `icon` before the title, and
        // the admin-next renderer already does the same, but the allowlist had
        // no `icon` entry so the value never left the server
        // (getgrav/grav-plugin-admin2#183).
        $fields = $this->serialize([
            'hero' => [
                'type' => 'fieldset',
                'title' => 'Hero settings',
                'icon' => 'gear',
                'collapsible' => true,
                'fields' => [],
            ],
        ]);

        $this->assertArrayHasKey('icon', $fields[0]);
        $this->assertSame('gear', $fields[0]['icon']);
    }

    #[Test]
    public function display_label_false_is_preserved_rather_than_treated_as_absent(): void
    {
        // `display_label: false` is the whole point of the attribute, so a
        // falsy-but-present value must survive; an isset()-style copy keeps it
        // while an empty() one would silently discard it.
        $fields = $this->serialize([
            'field' => ['type' => 'text', 'display_label' => false],
        ]);

        $this->assertArrayHasKey('display_label', $fields[0]);
        $this->assertFalse($fields[0]['display_label']);
    }

    #[Test]
    public function pages_field_list_options_reach_the_browser(): void
    {
        // Classic's pages field limits its list with `start_route`,
        // `show_fullpath` and `limit_levels`; without them in the allowlist a
        // "Start in" setting such as module-reuse's did nothing in admin2.
        $fields = $this->serialize([
            'header.module' => [
                'type' => 'pages',
                'start_route' => '/root',
                'show_all' => false,
                'show_modular' => true,
                'show_fullpath' => true,
                'limit_levels' => 2,
            ],
        ]);

        $this->assertSame('/root', $fields[0]['start_route']);
        $this->assertFalse($fields[0]['show_all']);
        $this->assertTrue($fields[0]['show_modular']);
        $this->assertTrue($fields[0]['show_fullpath']);
        $this->assertSame(2, $fields[0]['limit_levels']);
    }
}
