<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `?search=` on the regular (non-Flex) pages backend.
 *
 * The regular-pages path never read `search`, so a site without Flex pages got
 * every page back for any query, including one that matches nothing. It now
 * applies the Flex directory's own semantics: a case-insensitive substring
 * match on title, menu label, slug and route.
 */
#[CoversClass(PagesController::class)]
class PagesControllerSearchTest extends TestCase
{
    private function createController(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_search_test';
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);

        return new PagesController($grav, $config);
    }

    private function page(string $title, string $menu, string $slug, bool $published = true, ?string $route = null, ?string $rawRoute = null): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('title')->willReturn($title);
        $page->method('menu')->willReturn($menu);
        $page->method('slug')->willReturn($slug);
        $page->method('route')->willReturn($route ?? '/' . $slug);
        $page->method('rawRoute')->willReturn($rawRoute ?? $route ?? '/' . $slug);
        $page->method('published')->willReturn($published);
        $page->method('root')->willReturn(false);
        $page->method('exists')->willReturn(true);

        return $page;
    }

    private function searchHits(PageInterface $page, string $search): bool
    {
        return (new ReflectionMethod(PagesController::class, 'matchesSearch'))->invoke(null, $page, $search);
    }

    #[Test]
    public function matchesTitleMenuAndSlugCaseInsensitively(): void
    {
        $page = $this->page('Deposits and part payment', 'Deposits', 'deposits');

        self::assertTrue($this->searchHits($page, 'part pay'));
        self::assertTrue($this->searchHits($page, 'DEPOSIT'));
        self::assertTrue($this->searchHits($this->page('Home', 'Start here', 'home'), 'start'));
        self::assertTrue($this->searchHits($this->page('Home', 'Home', 'money-conventions'), 'conventions'));
        self::assertFalse($this->searchHits($page, 'zzzqqqnomatch'));
    }

    #[Test]
    public function matchesTheRouteCaseInsensitively(): void
    {
        // Nothing but the parent folder in the route says "docs".
        $page = $this->page('Getting started', 'Start', 'getting-started', route: '/docs/getting-started');

        self::assertTrue($this->searchHits($page, 'docs'));
        self::assertTrue($this->searchHits($page, '/DOCS/getting'));
        self::assertFalse($this->searchHits($page, 'guides'));

        // A hidden home page has the public route '/', but its raw route still matches.
        $home = $this->page('Welcome', 'Welcome', 'welcome', route: '/', rawRoute: '/landing');
        self::assertTrue($this->searchHits($home, 'landing'));
    }

    #[Test]
    public function searchTermIsTrimmedAndBlankMeansNoSearch(): void
    {
        $term = new ReflectionMethod(PagesController::class, 'searchTerm');

        self::assertSame('money', $term->invoke(null, ['search' => '  money ']));
        self::assertNull($term->invoke(null, ['search' => '   ']));
        self::assertNull($term->invoke(null, ['search' => '']));
        self::assertNull($term->invoke(null, []));
        self::assertNull($term->invoke(null, ['search' => ['money']]));
    }

    #[Test]
    public function searchCombinesWithTheOtherFilters(): void
    {
        $pages = [
            $this->page('Deposits', 'Deposits', 'deposits'),
            $this->page('Draft deposit notes', 'Draft', 'draft-deposit', published: false),
            $this->page('Refunds', 'Refunds', 'refunds'),
        ];

        $collect = new ReflectionMethod(PagesController::class, 'collectAndFilterPages');
        $controller = $this->createController();

        self::assertCount(2, $collect->invoke($controller, $pages, [], 'deposit'));
        self::assertCount(1, $collect->invoke($controller, $pages, ['published' => 'true'], 'deposit'));
        self::assertCount(0, $collect->invoke($controller, $pages, [], 'zzzqqqnomatch'));
        self::assertCount(3, $collect->invoke($controller, $pages, [], null));
    }
}
