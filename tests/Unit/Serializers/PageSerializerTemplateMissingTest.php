<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Tests\Unit\ModularSite;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Page details say when a module's template is missing, so the admin can show
 * a warning in the editor, whoever removed the template or never made it
 * (getgrav/grav-plugin-api#55). An ordinary page is never flagged.
 */
#[CoversClass(PageSerializer::class)]
class PageSerializerTemplateMissingTest extends TestCase
{
    private const OPTIONS = ['include_content' => false, 'include_media' => false];

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    private function page(string $template, bool $module): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('header')->willReturn(new \stdClass());
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('published')->willReturn(true);
        $page->method('isModule')->willReturn($module);
        $page->method('template')->willReturn($template);

        return $page;
    }

    #[Test]
    public function a_module_whose_template_is_not_on_the_site_is_flagged(): void
    {
        ModularSite::create();

        $data = (new PageSerializer())->serialize($this->page('modular/testimonials', true), self::OPTIONS);

        self::assertTrue($data['template_missing']);
        self::assertSame('modular/testimonials', $data['template']);
    }

    #[Test]
    public function a_module_with_a_registered_type_is_not_flagged_and_twig_is_not_asked(): void
    {
        ['twig' => $twig] = ModularSite::create();

        $data = (new PageSerializer())->serialize($this->page('modular/hero', true), self::OPTIONS);

        self::assertFalse($data['template_missing']);
        self::assertSame(0, $twig->inits);
    }

    #[Test]
    public function a_module_with_a_template_only_twig_knows_is_not_flagged(): void
    {
        ModularSite::create(twigTemplates: ['modular/lightbox.html.twig']);

        $data = (new PageSerializer())->serialize($this->page('modular/lightbox', true), self::OPTIONS);

        self::assertFalse($data['template_missing']);
    }

    #[Test]
    public function a_module_that_renders_with_cores_modular_default_is_flagged_unless_the_theme_has_one(): void
    {
        ModularSite::create(twigTemplates: ['modular/default.html.twig']);
        self::assertTrue((new PageSerializer())->serialize($this->page('modular/default', true), self::OPTIONS)['template_missing']);

        ModularSite::create(['modular/default' => 'Default']);
        self::assertFalse((new PageSerializer())->serialize($this->page('modular/default', true), self::OPTIONS)['template_missing']);
    }

    #[Test]
    public function the_template_core_renders_with_is_the_one_checked(): void
    {
        // Page::template() is the `template` header when there is one, which
        // can name any Twig template, so it is checked as written.
        ModularSite::create(twigTemplates: ['partials/card.html.twig']);
        $serializer = new PageSerializer();

        self::assertFalse($serializer->serialize($this->page('partials/card', true), self::OPTIONS)['template_missing']);
        self::assertTrue($serializer->serialize($this->page('partials/nope', true), self::OPTIONS)['template_missing']);
    }

    #[Test]
    public function an_ordinary_page_with_an_unknown_template_is_never_flagged(): void
    {
        // It falls back to the theme's default template, and headless sites
        // use types no theme knows.
        ['twig' => $twig] = ModularSite::create();

        $data = (new PageSerializer())->serialize($this->page('landing', false), self::OPTIONS);

        self::assertFalse($data['template_missing']);
        self::assertSame(0, $twig->inits);
    }

    #[Test]
    public function nothing_is_flagged_when_the_site_cannot_be_asked(): void
    {
        ModularSite::create(null);
        self::assertFalse((new PageSerializer())->serialize($this->page('modular/nope', true), self::OPTIONS)['template_missing']);

        ModularSite::create(twigThrows: true);
        self::assertFalse((new PageSerializer())->serialize($this->page('modular/nope', true), self::OPTIONS)['template_missing']);
    }

    #[Test]
    public function a_list_row_leaves_the_flag_out_and_never_asks_twig(): void
    {
        ['twig' => $twig] = ModularSite::create();

        $data = (new PageSerializer())->serialize($this->page('modular/testimonials', true), self::OPTIONS + ['include_template_state' => false]);

        self::assertArrayNotHasKey('template_missing', $data);
        self::assertSame(0, $twig->inits);
    }

    #[Test]
    public function many_modules_with_one_template_cost_one_twig_lookup(): void
    {
        ['twig' => $twig] = ModularSite::create();
        $serializer = new PageSerializer();

        $rows = $serializer->serializeCollection(
            array_fill(0, 20, $this->page('modular/testimonials', true)),
            self::OPTIONS,
        );

        self::assertCount(20, $rows);
        self::assertSame([true], array_values(array_unique(array_column($rows, 'template_missing'))));
        self::assertSame(['modular/testimonials.html.twig'], $twig->asked);
    }
}
