<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Services\PageTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `item.md` with `template: blogpost` renders with `blogpost`, and the pages
 * list says so even when a Flex-indexed object's template() still answers with
 * the file's name. Its language files keep the file's name (admin2#201).
 */
#[CoversClass(PageSerializer::class)]
#[CoversClass(PageTemplate::class)]
class PageSerializerTemplateHeaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-template-header-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function the_template_header_wins_over_the_file_name(): void
    {
        // What a Flex-indexed listing object does: the header names blogpost,
        // template() falls back to the file's name.
        $data = (new PageSerializer())->serialize($this->page('item.md', '.md', 'item'), $this->options());

        self::assertSame('blogpost', $data['template']);
    }

    #[Test]
    public function the_default_file_is_found_by_the_file_name_not_the_template(): void
    {
        file_put_contents($this->dir . '/item.md', "---\ntemplate: blogpost\n---\n");

        // A fully loaded page: template() already answers with the header.
        $data = (new PageSerializer())->serialize(
            $this->page('item.md', '.md', 'blogpost'),
            $this->options() + ['include_translations' => true],
        );

        self::assertSame('blogpost', $data['template']);
        self::assertTrue($data['has_default_file']);
    }

    #[Test]
    public function the_file_type_drops_the_language_and_a_modules_prefix(): void
    {
        self::assertSame('item', PageTemplate::fileType($this->page('item.de.md', '.de.md', 'blogpost')));
        self::assertSame('hero', PageTemplate::fileType($this->page('hero.md', '.md', 'modular/hero')));
    }

    private function page(string $name, string $extension, string $template): PageInterface
    {
        $page = $this->createMockForIntersectionOfInterfaces([PageInterface::class, TemplateHeaderPage::class]);
        $page->method('header')->willReturn((object) ['title' => 'Helloworld', 'template' => 'blogpost']);
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('name')->willReturn($name);
        $page->method('extension')->willReturn($extension);
        $page->method('template')->willReturn($template);
        $page->method('path')->willReturn($this->dir);
        $page->method('translatedLanguages')->willReturn([]);
        $page->method('untranslatedLanguages')->willReturn([]);

        return $page;
    }

    /** @return array<string, bool> */
    private function options(): array
    {
        return ['include_content' => false, 'include_media' => false, 'include_template_state' => false];
    }
}

/** The page class's translation helpers, as Grav's Page has them. */
interface TemplateHeaderPage
{
    /** @return array<string, string> */
    public function translatedLanguages(bool $onlyPublished = false): array;

    /** @return list<string> */
    public function untranslatedLanguages(bool $includeUnpublished = false): array;
}
