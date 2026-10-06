<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\ModularTemplates;
use Grav\Plugin\Api\Tests\Unit\ModularSite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A module whose template doesn't exist makes its parent page show core's red
 * "template not found" heading (getgrav/grav-plugin-api#55). The API allows
 * that and says so, so this is the one place that decides what "missing" is.
 */
#[CoversClass(ModularTemplates::class)]
class ModularTemplatesTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function a_registered_type_exists_and_twig_is_not_asked(): void
    {
        ['grav' => $grav, 'twig' => $twig] = ModularSite::create();
        $templates = new ModularTemplates($grav);

        self::assertFalse($templates->isMissing('modular/text'));
        self::assertSame(0, $twig->inits);
    }

    #[Test]
    public function a_type_that_is_not_registered_and_not_in_twig_is_missing(): void
    {
        ['grav' => $grav] = ModularSite::create();

        self::assertTrue((new ModularTemplates($grav))->isMissing('modular/testimonials'));
    }

    #[Test]
    public function a_template_only_twig_knows_exists(): void
    {
        // A plugin that adds a Twig path without registering its types.
        ['grav' => $grav] = ModularSite::create(twigTemplates: ['modular/lightbox.html.twig']);
        $templates = new ModularTemplates($grav);

        self::assertFalse($templates->isMissing('modular/lightbox'));
        self::assertTrue($templates->isMissing('modular/gallery'));
    }

    #[Test]
    public function cores_own_modular_default_template_does_not_count(): void
    {
        // Twig always finds `modular/default.html.twig`: core ships it, and it
        // is the "template not found" heading itself.
        ['grav' => $grav] = ModularSite::create(twigTemplates: ['modular/default.html.twig']);
        self::assertTrue((new ModularTemplates($grav))->isMissing('modular/default'));

        // A theme with its own `templates/modular/default.html.twig` registers it.
        ['grav' => $grav] = ModularSite::create(['modular/default' => 'Default']);
        self::assertFalse((new ModularTemplates($grav))->isMissing('modular/default'));
    }

    #[Test]
    public function nothing_is_missing_when_the_registry_or_twig_cannot_be_asked(): void
    {
        ['grav' => $grav] = ModularSite::create(null);
        self::assertFalse((new ModularTemplates($grav))->isMissing('modular/anything'));
        self::assertNull((new ModularTemplates($grav))->types());
        self::assertNull((new ModularTemplates($grav))->warning('modular/anything'));

        // A Twig that cannot be built says nothing about the template.
        ['grav' => $grav] = ModularSite::create(twigThrows: true);
        self::assertFalse((new ModularTemplates($grav))->isMissing('modular/anything'));

        // Nor does a site with no Twig service at all.
        $grav = \Grav\Plugin\Api\Tests\Unit\TestHelper::createMockGrav(['pages' => new class {
            public static function types(): array { return ['default' => 'Default']; }

            public static function modularTypes(): array { return []; }
        }]);
        self::assertFalse((new ModularTemplates($grav))->isMissing('modular/anything'));
    }

    #[Test]
    public function an_answer_is_remembered_per_template_name(): void
    {
        ['grav' => $grav, 'twig' => $twig] = ModularSite::create();
        $templates = new ModularTemplates($grav);

        for ($i = 0; $i < 5; $i++) {
            self::assertTrue($templates->isMissing('modular/testimonials'));
        }
        self::assertTrue($templates->isMissing('modular/gallery'));

        self::assertSame(['modular/testimonials.html.twig', 'modular/gallery.html.twig'], $twig->asked);
    }

    #[Test]
    public function the_registered_spelling_is_found_without_regard_to_case(): void
    {
        ['grav' => $grav] = ModularSite::create();
        $templates = new ModularTemplates($grav);

        self::assertSame('modular/hero', $templates->registered('modular/Hero'));
        self::assertSame('modular/text', $templates->registered('modular/text'));
        self::assertNull($templates->registered('modular/nope'));
    }

    #[Test]
    public function the_warning_names_the_template_and_lists_the_types_on_the_site(): void
    {
        ['grav' => $grav] = ModularSite::create();
        $templates = new ModularTemplates($grav);

        $warning = $templates->warning('modular/testimonials');
        self::assertSame(['field', 'code', 'message'], array_keys($warning));
        self::assertSame('template', $warning['field']);
        self::assertSame('template_missing', $warning['code']);
        self::assertSame(
            "Template 'modular/testimonials' doesn't exist on this site, so the page this module belongs to will show a 'template not found' error until it does. Modular types on this site: modular/hero, modular/text.",
            $warning['message'],
        );

        $header = $templates->warning('modular/nope', 'header.template');
        self::assertSame('header.template', $header['field']);
        self::assertStringStartsWith("The 'template' header names 'modular/nope', which doesn't exist on this site", $header['message']);

        self::assertNull($templates->warning('modular/hero'));
    }

    #[Test]
    public function a_site_with_no_modular_types_says_so(): void
    {
        ['grav' => $grav] = ModularSite::create([]);

        $warning = (new ModularTemplates($grav))->warning('modular/default');

        self::assertStringContainsString('This site has no modular types', $warning['message']);
    }
}
