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
 * Unit coverage for PagesController::matchesFilters() — the single predicate the
 * list endpoint now uses for every filter key.
 *
 * Regression guard for getgrav/grav-plugin-admin2#121: the flat /pages listing
 * used to apply published/visible/routable through the flex
 * withPublished()/withVisible()/withRoutable() shortcuts, guarded by
 * method_exists(). Those methods live on PageCollection, but
 * $directory->getCollection() returns a PageIndex — so the guard never fired and
 * the three boolean filters were silently ignored, returning every page. The fix
 * routes all filters through matchesFilters(); these tests pin that it honours
 * the boolean-string forms the query layer produces.
 */
#[CoversClass(PagesController::class)]
class PagesControllerFilterTest extends TestCase
{
    private function createController(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_filter_test';
            }
        };
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);

        return new PagesController($grav, $config);
    }

    /**
     * @param array{published?:bool,visible?:bool,routable?:bool,template?:string} $pageState
     * @param array<string,string> $filters
     */
    private function filterMatches(array $pageState, array $filters): bool
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('published')->willReturn($pageState['published'] ?? true);
        $page->method('visible')->willReturn($pageState['visible'] ?? false);
        $page->method('routable')->willReturn($pageState['routable'] ?? true);
        $page->method('template')->willReturn($pageState['template'] ?? 'default');
        $page->method('route')->willReturn('/example');

        $method = new ReflectionMethod(PagesController::class, 'matchesFilters');

        return $method->invoke($this->createController(), $page, $filters);
    }

    #[Test]
    public function visibleTrueFilterKeepsVisiblePagesAndDropsInvisibleOnes(): void
    {
        self::assertTrue($this->filterMatches(['visible' => true], ['visible' => 'true']));
        self::assertFalse($this->filterMatches(['visible' => false], ['visible' => 'true']));
    }

    #[Test]
    public function visibleFalseFilterKeepsInvisiblePagesAndDropsVisibleOnes(): void
    {
        self::assertTrue($this->filterMatches(['visible' => false], ['visible' => 'false']));
        self::assertFalse($this->filterMatches(['visible' => true], ['visible' => 'false']));
    }

    #[Test]
    public function publishedFilterHonoursBothStates(): void
    {
        self::assertTrue($this->filterMatches(['published' => true], ['published' => 'true']));
        self::assertFalse($this->filterMatches(['published' => false], ['published' => 'true']));
        self::assertTrue($this->filterMatches(['published' => false], ['published' => 'false']));
        self::assertFalse($this->filterMatches(['published' => true], ['published' => 'false']));
    }

    #[Test]
    public function routableFilterHonoursBothStates(): void
    {
        self::assertTrue($this->filterMatches(['routable' => true], ['routable' => 'true']));
        self::assertFalse($this->filterMatches(['routable' => false], ['routable' => 'true']));
        self::assertTrue($this->filterMatches(['routable' => false], ['routable' => 'false']));
    }

    #[Test]
    public function templateFilterMatchesExactTemplateOnly(): void
    {
        self::assertTrue($this->filterMatches(['template' => 'blog'], ['template' => 'blog']));
        self::assertFalse($this->filterMatches(['template' => 'default'], ['template' => 'blog']));
    }

    #[Test]
    public function multipleFiltersAreAndedTogether(): void
    {
        // Visible AND blog: a visible non-blog page must be rejected.
        self::assertTrue($this->filterMatches(['visible' => true, 'template' => 'blog'], ['visible' => 'true', 'template' => 'blog']));
        self::assertFalse($this->filterMatches(['visible' => true, 'template' => 'default'], ['visible' => 'true', 'template' => 'blog']));
        self::assertFalse($this->filterMatches(['visible' => false, 'template' => 'blog'], ['visible' => 'true', 'template' => 'blog']));
    }

    #[Test]
    public function numericBooleanFormsAreAccepted(): void
    {
        self::assertTrue($this->filterMatches(['visible' => true], ['visible' => '1']));
        self::assertTrue($this->filterMatches(['visible' => false], ['visible' => '0']));
    }
}
