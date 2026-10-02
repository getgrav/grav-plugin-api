<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The API router answers inside RequestProcessor, ahead of TwigProcessor, so
 * Twig's environment does not exist yet while a page is serialized. Rendering a
 * page (`?render=true`) runs content Twig and shortcodes, which fail on a null
 * environment for modules and any Twig-backed shortcode. The serializer has to
 * initialise Twig before it asks the page for its HTML.
 */
#[CoversClass(PageSerializer::class)]
class PageSerializerTwigInitTest extends TestCase
{
    /** @var list<string> the order in which Twig init and page rendering happened */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $calls = &$this->calls;

        TestHelper::createMockGrav([
            'twig' => new class ($calls) {
                public function __construct(private array &$calls) {}

                public function init(): static
                {
                    $this->calls[] = 'twig.init';

                    return $this;
                }
            },
        ]);
    }

    protected function tearDown(): void
    {
        \Grav\Common\Grav::resetInstance();
    }

    private function page(): PageRenderingInterface
    {
        $page = $this->createMock(PageRenderingInterface::class);
        $page->method('header')->willReturn(new \stdClass());
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('published')->willReturn(true);
        $page->method('content')->willReturnCallback(function (): string {
            $this->calls[] = 'page.content';

            return '<p>rendered</p>';
        });

        return $page;
    }

    #[Test]
    public function render_content_initialises_twig_before_the_page_renders(): void
    {
        $data = (new PageSerializer())->serialize($this->page(), [
            'include_content' => false,
            'include_media' => false,
            'render_content' => true,
        ]);

        self::assertSame('<p>rendered</p>', $data['content_html']);
        self::assertSame(['twig.init', 'page.content'], $this->calls);
    }

    #[Test]
    public function a_plain_serialize_leaves_twig_alone(): void
    {
        (new PageSerializer())->serialize($this->page(), ['include_content' => false, 'include_media' => false]);

        self::assertSame([], $this->calls);
    }
}

/**
 * The rendering call the serializer makes, spelled out so the page can be
 * mocked whichever PageInterface (Grav's or the test stub) is loaded.
 */
interface PageRenderingInterface extends PageInterface
{
    public function content($var = null);
}
