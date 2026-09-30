<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for getgrav/grav-plugin-api#54 and #53.
 *
 * #54: POST /pages accepted a slug a sibling already had under another order
 * prefix (`02._dup` and `03._dup`). Grav routes by slug, so one of the two never
 * showed in listings, and a later reorder mapped both onto one slug, renamed the
 * wrong folder and died halfway, leaving the children under `_temp_` names.
 *
 * #53: move, reorder, copy and delete rename folders directly, so nothing cleared
 * the Flex pages index, and GET /pages?children_of= kept listing the old state
 * until the index cache expired.
 */
#[CoversClass(PagesController::class)]
class PagesControllerSlugClashTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-slugclash-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/parent', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
        Grav::resetInstance();
        parent::tearDown();
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param list<string> $folders */
    private function folders(array $folders): void
    {
        foreach ($folders as $folder) {
            mkdir($this->dir . '/parent/' . $folder, 0775, true);
        }
    }

    /** @return list<string> */
    private function listing(): array
    {
        return array_values(array_diff(scandir($this->dir . '/parent') ?: [], ['.', '..']));
    }

    /**
     * @param PageInterface|null $parentPage served by find() for '/parent'
     * @param FlexDirectory|null $flexDirectory the Flex pages directory, when the test wants one
     */
    private function controller(?PageInterface $parentPage = null, ?FlexDirectory $flexDirectory = null, array $extraPages = []): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        $pages = new class ($parentPage, $extraPages) {
            public int $marked = 0;

            /** @param array<string, PageInterface> $extra */
            public function __construct(private readonly ?PageInterface $parent, private readonly array $extra) {}

            public function enablePages(): void {}

            public function reset(): void {}

            public function markChanged(): void
            {
                $this->marked++;
            }

            public function find(string $route): ?PageInterface
            {
                return $route === '/parent' ? $this->parent : ($this->extra[$route] ?? null);
            }
        };

        $language = new class {
            public function getActive(): ?string { return null; }

            public function enabled(): bool { return false; }

            public function resetFallbackPageExtensions(): void {}
        };

        $base = $this->dir;
        $locator = new class ($base) {
            public function __construct(private readonly string $base) {}

            public function findResource(string $uri, bool $absolute = false): string
            {
                return str_starts_with($uri, 'page://') ? $this->base : $this->base . '/cache';
            }
        };

        $services = ['config' => $config, 'pages' => $pages, 'language' => $language, 'locator' => $locator];
        if ($flexDirectory !== null) {
            $services['flex_objects'] = new class ($flexDirectory) {
                public function __construct(private readonly FlexDirectory $directory) {}

                public function getDirectory(string $type): FlexDirectory
                {
                    return $this->directory;
                }
            };
        }

        return new PagesController(TestHelper::createMockGrav($services), $config);
    }

    private function parentPage(string $path, array $childFolders): PageInterface
    {
        $children = [];
        foreach ($childFolders as $folder) {
            $slug = preg_replace('/^\d+\./', '', $folder);
            $child = $this->createMock(PageInterface::class);
            $child->method('slug')->willReturn($slug);
            $child->method('path')->willReturn($path . '/' . $folder);
            $children[] = $child;
        }

        $parent = $this->createMock(PageInterface::class);
        $parent->method('path')->willReturn($path);
        $parent->method('route')->willReturn('/parent');
        $parent->method('rawRoute')->willReturn('/parent');
        $parent->method('children')->willReturn(new \ArrayIterator($children));
        $parent->method('header')->willReturn((object) ['title' => 'Parent']);

        return $parent;
    }

    private function request(array $body, array $routeParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        $superAdmin = TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]);

        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/pages',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user' => $superAdmin,
                'json_body' => $body,
                'route_params' => $routeParams,
            ],
        );
    }

    private function call(PagesController $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(PagesController::class))->getMethod($method)->invoke($controller, ...$args);
    }

    // -------------------------------------------------------
    // #54: slug clash check (create, copy, move, reorganize)
    // -------------------------------------------------------

    #[Test]
    public function a_sibling_with_the_same_slug_under_another_prefix_is_a_clash(): void
    {
        $this->folders(['01._a', '02._dup']);

        self::assertSame('02._dup', $this->call($this->controller(), 'findSlugClash', $this->dir . '/parent', '03._dup'));
    }

    #[Test]
    public function an_unprefixed_sibling_clashes_with_a_prefixed_one_and_the_reverse(): void
    {
        $this->folders(['02.foo', 'bar']);

        self::assertSame('02.foo', $this->call($this->controller(), 'findSlugClash', $this->dir . '/parent', 'foo'));
        self::assertSame('bar', $this->call($this->controller(), 'findSlugClash', $this->dir . '/parent', '07.bar'));
    }

    #[Test]
    public function ordinary_pages_clash_the_same_way_modules_do(): void
    {
        // Grav routes by slug for every page, so `02.foo` and `03.foo` are
        // ambiguous whether or not the folder starts with an underscore.
        $this->folders(['02.foo']);

        self::assertSame('02.foo', $this->call($this->controller(), 'findSlugClash', $this->dir . '/parent', '03.foo'));
    }

    #[Test]
    public function different_slugs_and_plain_files_do_not_clash(): void
    {
        $this->folders(['01._a', '02._dup']);
        file_put_contents($this->dir . '/parent/03._other', 'a file, not a page folder');

        $controller = $this->controller();
        self::assertNull($this->call($controller, 'findSlugClash', $this->dir . '/parent', '03._other'));
        self::assertNull($this->call($controller, 'findSlugClash', $this->dir . '/parent', '03._dupe'));
        self::assertNull($this->call($controller, 'findSlugClash', $this->dir . '/missing', '03._dup'));
    }

    #[Test]
    public function a_folder_that_is_about_to_vacate_is_not_a_clash(): void
    {
        // Moving `02.foo` to `05.foo` inside one parent.
        $this->folders(['02.foo']);

        $controller = $this->controller();
        self::assertNull($this->call($controller, 'findSlugClash', $this->dir . '/parent', '05.foo', $this->dir . '/parent/02.foo'));
        self::assertSame('02.foo', $this->call($controller, 'findSlugClash', $this->dir . '/parent', '05.foo', $this->dir . '/parent/other'));
    }

    #[Test]
    public function create_refuses_a_module_whose_slug_a_sibling_has_under_another_number(): void
    {
        $this->folders(['01._a', '02._dup']);
        $controller = $this->controller($this->parentPage($this->dir . '/parent', ['01._a', '02._dup']));

        try {
            $controller->create($this->request([
                'route' => '/parent/_dup',
                'title' => 'Dup 2',
                'template' => 'modular/text',
                'kind' => 'module',
                'order' => 3,
            ]));
            self::fail('create() accepted a duplicate module slug');
        } catch (ValidationException $e) {
            self::assertStringContainsString('02._dup', $e->getMessage());
        }

        self::assertSame(['01._a', '02._dup'], $this->listing(), 'nothing may be written');
    }

    // -------------------------------------------------------
    // #54: reorder validates first and rolls back
    // -------------------------------------------------------

    #[Test]
    public function reorder_refuses_a_slug_two_children_share_and_renames_nothing(): void
    {
        // The state POST /pages used to be able to produce.
        $this->folders(['01._a', '02._dup', '03._dup']);
        $controller = $this->controller($this->parentPage($this->dir . '/parent', ['01._a', '02._dup', '03._dup']));

        try {
            $controller->reorder($this->request(['order' => ['_dup', '_a']], ['route' => 'parent']));
            self::fail('reorder() accepted an ambiguous slug');
        } catch (ValidationException $e) {
            self::assertStringContainsString('_dup', $e->getMessage());
            self::assertStringContainsString('02._dup', $e->getMessage());
            self::assertStringContainsString('03._dup', $e->getMessage());
        }

        self::assertSame(['01._a', '02._dup', '03._dup'], $this->listing(), 'no folder may be renamed or left under a _temp_ name');
    }

    #[Test]
    public function reorder_refuses_a_slug_listed_twice(): void
    {
        $this->folders(['01._a', '02._b']);
        $controller = $this->controller($this->parentPage($this->dir . '/parent', ['01._a', '02._b']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('more than once');
        $controller->reorder($this->request(['order' => ['_a', '_a']], ['route' => 'parent']));
    }

    #[Test]
    public function reorder_refuses_when_a_temp_name_is_already_taken(): void
    {
        // What an earlier failed reorder used to leave behind. Renaming onto it
        // would fail part-way (or, when it is empty, silently replace it).
        $this->folders(['01._a', '02._b', '_temp_1__b']);
        file_put_contents($this->dir . '/parent/_temp_1__b/default.md', 'stranded');
        $controller = $this->controller($this->parentPage($this->dir . '/parent', ['01._a', '02._b', '_temp_1__b']));

        try {
            $controller->reorder($this->request(['order' => ['_b', '_a']], ['route' => 'parent']));
            self::fail('reorder() went ahead over a folder it does not own');
        } catch (ValidationException $e) {
            self::assertStringContainsString('_temp_1__b', $e->getMessage());
        }

        self::assertSame(['01._a', '02._b', '_temp_1__b'], $this->listing());
    }

    #[Test]
    public function reorder_still_reorders_a_clean_parent(): void
    {
        $this->folders(['01._a', '02._b', '03._c']);
        $controller = $this->controller($this->parentPage($this->dir . '/parent', ['01._a', '02._b', '03._c']));

        $controller->reorder($this->request(['order' => ['_c', '_a', '_b']], ['route' => 'parent']));

        self::assertSame(['01._c', '02._a', '03._b'], $this->listing());
    }

    #[Test]
    public function a_failed_rename_puts_every_folder_back(): void
    {
        $this->folders(['01._a', '02._b', '03._c']);
        // `_c` cannot take its new name: a non-empty directory the plan did not
        // know about sits on it. Before the fix the exception left `_a` and `_b`
        // under their temp names.
        mkdir($this->dir . '/parent/taken');
        file_put_contents($this->dir . '/parent/taken/file', 'x');

        $p = $this->dir . '/parent';
        $plan = [
            ['old' => "$p/01._a", 'temp' => "$p/_temp_1__a", 'final' => "$p/02._a"],
            ['old' => "$p/02._b", 'temp' => "$p/_temp_1__b", 'final' => "$p/01._b"],
            ['old' => "$p/03._c", 'temp' => "$p/_temp_3__c", 'final' => "$p/taken"],
        ];

        try {
            $this->call($this->controller(), 'applyRenames', $plan);
            self::fail('applyRenames() swallowed the failure');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('rolled back', $e->getMessage());
        }

        self::assertSame(['01._a', '02._b', '03._c', 'taken'], $this->listing());
    }

    #[Test]
    public function reorganize_refuses_a_move_into_a_parent_that_has_the_slug_under_another_prefix(): void
    {
        $this->folders(['02._dup']);
        mkdir($this->dir . '/elsewhere/_dup', 0775, true);

        $controller = $this->controller(
            $this->parentPage($this->dir . '/parent', ['02._dup']),
            null,
            ['/elsewhere/_dup' => $this->pageAt('/elsewhere/_dup', '_dup', $this->dir . '/elsewhere/_dup')],
        );

        try {
            $controller->reorganize($this->request(['operations' => [
                ['route' => '/elsewhere/_dup', 'parent' => '/parent', 'position' => 1],
            ]]));
            self::fail('reorganize() created a second page with the same slug');
        } catch (ValidationException $e) {
            self::assertStringContainsString('_dup', $e->getMessage());
        }

        self::assertSame(['02._dup'], $this->listing());
        self::assertDirectoryExists($this->dir . '/elsewhere/_dup');
    }

    #[Test]
    public function reorganize_lets_a_page_keep_its_slug_when_the_clash_is_the_one_it_replaces(): void
    {
        // Renumbering inside one parent never creates a new clash.
        $this->folders(['02.foo', '03.bar']);
        $controller = $this->controller(
            $this->parentPage($this->dir . '/parent', ['02.foo', '03.bar']),
            null,
            [
                '/parent/foo' => $this->pageAt('/parent/foo', 'foo', $this->dir . '/parent/02.foo', 2),
                '/parent/bar' => $this->pageAt('/parent/bar', 'bar', $this->dir . '/parent/03.bar', 3),
            ],
        );

        $controller->reorganize($this->request(['operations' => [
            ['route' => '/parent/foo', 'position' => 3],
            ['route' => '/parent/bar', 'position' => 2],
        ]]));

        self::assertSame(['02.bar', '03.foo'], $this->listing());
    }

    private function pageAt(string $route, string $slug, string $path, ?int $order = null): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn($route);
        $page->method('rawRoute')->willReturn($route);
        $page->method('slug')->willReturn($slug);
        $page->method('path')->willReturn($path);
        $page->method('order')->willReturn($order);
        $page->method('header')->willReturn((object) ['title' => $slug]);
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('parent')->willReturn(null);

        return $page;
    }

    // -------------------------------------------------------
    // #53: clearing the pages cache clears the Flex index
    // -------------------------------------------------------

    #[Test]
    public function clearing_the_pages_cache_also_clears_the_flex_pages_directory(): void
    {
        $directory = $this->createMock(FlexDirectory::class);
        $directory->method('isEnabled')->willReturn(true);
        $directory->expects(self::once())->method('clearCache');

        $controller = $this->controller(null, $directory);
        $this->call($controller, 'clearPagesCache');

        self::assertSame(1, Grav::instance()['pages']->marked, 'core pages cache is still marked changed');
    }

    #[Test]
    public function clearing_the_pages_cache_without_flex_still_marks_pages_changed(): void
    {
        $controller = $this->controller();
        $this->call($controller, 'clearPagesCache');

        self::assertSame(1, Grav::instance()['pages']->marked);
    }
}
