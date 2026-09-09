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
 * Regression guard for getgrav/grav-plugin-api#34.
 *
 * A page carrying `routes.default: ''` in its header has a legitimately empty
 * public route: Page::route() returns the literal empty string, and the Flex
 * page trait does the same. The listing code skipped Grav's virtual pages-root
 * container by asking whether the page had a route at all, and an empty string
 * is falsy, so such a page was dropped from every listing. It vanished from the
 * pages section and the parent picker in Admin Next, and went uncounted on the
 * dashboard.
 *
 * The virtual root is now identified by root(), which is declared on
 * PageRoutableInterface and so answers on both the classic and Flex page
 * objects. That matters because exists() alone cannot stand in for it: it is
 * false for the classic root but true for the Flex one.
 */
#[CoversClass(PagesController::class)]
class PagesControllerEmptyRouteAliasTest extends TestCase
{
    private function createController(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_empty_route_test';
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);

        return new PagesController($grav, $config);
    }

    private function page(?string $route, ?string $rawRoute, bool $isRoot, bool $exists): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn($route);
        $page->method('rawRoute')->willReturn($rawRoute);
        $page->method('root')->willReturn($isRoot);
        $page->method('exists')->willReturn($exists);

        return $page;
    }

    /** @param array<string,string> $filters */
    private function filterMatches(PageInterface $page, array $filters): bool
    {
        $method = new ReflectionMethod(PagesController::class, 'matchesFilters');

        return $method->invoke($this->createController(), $page, $filters);
    }

    /**
     * @param list<PageInterface> $instances
     * @param array<string,string> $filters
     * @return list<PageInterface>
     */
    private function collect(array $instances, array $filters = []): array
    {
        $method = new ReflectionMethod(PagesController::class, 'collectAndFilterPages');

        return $method->invoke($this->createController(), $instances, $filters);
    }

    #[Test]
    public function listingKeepsAPageWhoseRouteAliasIsEmpty(): void
    {
        // The page the reporter had: `routes: {default: ''}` on a real file.
        $aliased = $this->page('', '/articles', false, true);
        $ordinary = $this->page('/blog', '/blog', false, true);

        $collected = $this->collect([$aliased, $ordinary]);

        self::assertSame([$aliased, $ordinary], $collected, 'an empty route alias must not drop the page');
    }

    #[Test]
    public function listingStillSkipsTheVirtualRootOnBothPageEngines(): void
    {
        // Grav's virtual pages-root: no file on disk, no route of any kind.
        $classicRoot = $this->page(null, null, true, false);
        // The Flex root differs in exactly the way that matters here: it
        // reports that it exists, so an exists()-only guard would list it.
        $flexRoot = $this->page(null, null, true, true);
        $real = $this->page('/blog', '/blog', false, true);

        self::assertSame([$real], $this->collect([$classicRoot, $flexRoot, $real]));
    }

    #[Test]
    public function parentFilterStillFindsAPageWithAnEmptyRouteAlias(): void
    {
        // Public route is empty, so a prefix match against it can never hit.
        // The structural route is what places the page in the tree.
        $aliased = $this->page('', '/articles', false, true);

        self::assertTrue($this->filterMatches($aliased, ['parent' => 'articles']));
    }

    #[Test]
    public function parentFilterStillMatchesOnThePublicRoute(): void
    {
        $ordinary = $this->page('/blog/post', '/blog/post', false, true);

        self::assertTrue($this->filterMatches($ordinary, ['parent' => 'blog']));
        self::assertFalse($this->filterMatches($ordinary, ['parent' => 'shop']));
    }
}
