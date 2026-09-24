<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Page listings read one folder's children instead of every page in the site,
 * and `fields=summary` leaves the frontmatter out of each row.
 */
#[CoversClass(PagesController::class)]
#[CoversClass(PageSerializer::class)]
class PagesControllerChildrenListingTest extends TestCase
{
    private function createController(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_children_test';
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);

        return new PagesController($grav, $config);
    }

    private function page(string $path, string $rawRoute, ?string $route = null, bool $root = false): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn($path);
        $page->method('rawRoute')->willReturn($rawRoute);
        $page->method('route')->willReturn($route ?? $rawRoute);
        $page->method('root')->willReturn($root);

        return $page;
    }

    /** @return array<string, PageInterface> */
    private function candidates(PagesController $controller, Pages $pages, array $filters): array
    {
        $result = (new ReflectionMethod(PagesController::class, 'candidatePages'))->invoke($controller, $pages, $filters);

        return is_array($result) ? $result : iterator_to_array($result);
    }

    #[Test]
    public function children_of_reads_only_the_parents_children(): void
    {
        $a = $this->page('/p/docs/01.a', '/docs/a');
        $b = $this->page('/p/docs/02.b', '/docs/b');

        $pages = $this->createMock(Pages::class);
        $pages->expects(self::never())->method('instances');
        $pages->method('find')->with('/docs', true)->willReturn($this->page('/p/docs', '/docs'));
        $pages->method('children')->with('/p/docs')->willReturn(new \ArrayIterator(['/p/docs/01.a' => $a, '/p/docs/02.b' => $b]));

        self::assertSame(['/p/docs/01.a' => $a, '/p/docs/02.b' => $b], $this->candidates($this->createController(), $pages, ['children_of' => 'docs/']));
    }

    #[Test]
    public function root_listings_read_the_pages_roots_children(): void
    {
        $home = $this->page('/p/01.home', '/home', '/');

        $pages = $this->createMock(Pages::class);
        $pages->expects(self::never())->method('instances');
        $pages->method('root')->willReturn($this->page('/p', '', '', true));
        $pages->method('children')->with('/p')->willReturn(new \ArrayIterator(['/p/01.home' => $home]));

        $controller = $this->createController();
        self::assertSame(['/p/01.home' => $home], $this->candidates($controller, $pages, ['children_of' => '/']));
        self::assertSame(['/p/01.home' => $home], $this->candidates($controller, $pages, ['root' => 'true']));
    }

    #[Test]
    public function an_unresolved_or_aliased_parent_falls_back_to_every_page(): void
    {
        $all = ['/p/x' => $this->page('/p/x', '/x')];
        $controller = $this->createController();

        // No page at that route.
        $pages = $this->createMock(Pages::class);
        $pages->method('find')->willReturn(null);
        $pages->expects(self::once())->method('instances')->willReturn($all);
        self::assertSame($all, $this->candidates($controller, $pages, ['children_of' => '/missing']));

        // The route table points at a page through an alias, so neither of its
        // routes is the value the filter compares against.
        $pages = $this->createMock(Pages::class);
        $pages->method('find')->willReturn($this->page('/p/real', '/real'));
        $pages->expects(self::once())->method('instances')->willReturn($all);
        self::assertSame($all, $this->candidates($controller, $pages, ['children_of' => '/alias']));

        // No children_of or root filter at all.
        $pages = $this->createMock(Pages::class);
        $pages->expects(self::once())->method('instances')->willReturn($all);
        self::assertSame($all, $this->candidates($controller, $pages, ['published' => 'true', 'root' => 'false']));
    }

    #[Test]
    public function fields_summary_turns_off_the_header_and_nothing_else(): void
    {
        $options = new ReflectionMethod(PagesController::class, 'listOptions');
        $controller = $this->createController();

        $default = $options->invoke($controller, TestHelper::createMockRequest());
        $summary = $options->invoke($controller, TestHelper::createMockRequest(queryParams: ['fields' => 'summary']));
        $unknown = $options->invoke($controller, TestHelper::createMockRequest(queryParams: ['fields' => 'everything']));

        self::assertTrue($default['include_header']);
        self::assertFalse($summary['include_header']);
        self::assertTrue($unknown['include_header']);
        unset($default['include_header'], $summary['include_header']);
        self::assertSame($default, $summary);
    }

    #[Test]
    public function the_serializer_drops_only_the_header_key(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('header')->willReturn((object) ['title' => 'From header', 'published' => false, 'visible' => false]);
        $page->method('route')->willReturn('/docs/a');
        $page->method('rawRoute')->willReturn('/docs/a');
        $page->method('slug')->willReturn('a');
        $page->method('children')->willReturn(new \ArrayIterator([]));

        $serializer = new PageSerializer();
        $options = ['include_content' => false, 'include_media' => false];
        $full = $serializer->serialize($page, $options);
        $summary = $serializer->serialize($page, $options + ['include_header' => false]);

        self::assertArrayHasKey('header', $full);
        self::assertArrayNotHasKey('header', $summary);
        // Values that come from the frontmatter are still read from it.
        self::assertSame('From header', $summary['title']);
        self::assertFalse($summary['published']);
        self::assertFalse($summary['visible']);

        unset($full['header']);
        self::assertSame($full, $summary);
    }
}
