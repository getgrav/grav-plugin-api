<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\PageOrdering;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for getgrav/grav-admin-next#36: a reorganize batch that
 * holds an op for a folder and an op for a page inside it.
 *
 *   parent/
 *     01.chapter/
 *       01.child1/
 *       02.child2/
 *     02.chapter2/
 *
 * Every op went through a temp name, even one that kept its number, so the
 * folder carried its children away. An op for a child then found nothing at
 * its old path and was skipped while the request reported success, or its
 * temp folder vanished and the rename failed.
 *
 * The other top-level folders cover the drops the Tree and Columns views can
 * now make: into a page with no children, to the top level, a folder with 60
 * children, an unnumbered page, a folder using three-digit numbers, and a
 * slug that is already taken where the page lands.
 */
#[CoversClass(PagesController::class)]
class PagesControllerReorganizeNestingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-reorg-nesting-' . bin2hex(random_bytes(6));
        foreach (['01.chapter/01.child1', '01.chapter/02.child2', '02.chapter2'] as $folder) {
            mkdir($this->dir . '/parent/' . $folder, 0775, true);
        }
        foreach (['02.empty', '04.mixed/01.a', '04.mixed/02.b', '04.mixed/c', '05.wide/001.w1', '05.wide/002.w2', '07.chapter2'] as $folder) {
            mkdir($this->dir . '/' . $folder, 0775, true);
        }
        for ($i = 1; $i <= 60; $i++) {
            mkdir(sprintf('%s/03.sixty/%02d.c%d', $this->dir, $i, $i), 0775, true);
        }
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

    /** @return list<string> */
    private function listing(string $sub): array
    {
        return array_values(array_diff(scandir($this->dir . '/' . $sub) ?: [], ['.', '..']));
    }

    private function page(string $route, string $folder, ?int $order): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn($route);
        $page->method('rawRoute')->willReturn($route);
        $page->method('slug')->willReturn(basename($route));
        $page->method('path')->willReturn($this->dir . '/' . $folder);
        $page->method('order')->willReturn($order);
        $page->method('header')->willReturn((object) ['title' => basename($route)]);
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('parent')->willReturn(null);

        return $page;
    }

    /**
     * A page for every folder on disk, keyed by route.
     *
     * @return array<string, PageInterface>
     */
    private function scan(string $sub = '', string $route = ''): array
    {
        $found = [];
        foreach (scandir($this->dir . ($sub === '' ? '' : '/' . $sub)) ?: [] as $entry) {
            $folder = $sub === '' ? $entry : $sub . '/' . $entry;
            if ($entry[0] === '.' || !is_dir($this->dir . '/' . $folder)) {
                continue;
            }
            [$order, $slug] = PageOrdering::parse($entry);
            $found[$route . '/' . $slug] = $this->page($route . '/' . $slug, $folder, $order);
            $found += $this->scan($folder, $route . '/' . $slug);
        }

        return $found;
    }

    private function controller(): PagesController
    {
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);

        $known = $this->scan();
        $pages = new class ($known, $this->page('/', '', null)) {
            /** @param array<string, PageInterface> $known */
            public function __construct(private readonly array $known, private readonly PageInterface $root) {}

            public function root(): PageInterface
            {
                return $this->root;
            }

            public function enablePages(): void {}

            public function reset(): void {}

            public function markChanged(): void {}

            public function find(string $route): ?PageInterface
            {
                return $this->known[$route] ?? null;
            }
        };
        $language = new class {
            public function getActive(): ?string { return null; }

            public function enabled(): bool { return false; }

            public function resetFallbackPageExtensions(): void {}
        };
        $locator = new class ($this->dir) {
            public function __construct(private readonly string $base) {}

            public function findResource(string $uri, bool $absolute = false): string
            {
                return str_starts_with($uri, 'page://') ? $this->base : $this->base . '/cache';
            }
        };

        return new PagesController(
            TestHelper::createMockGrav(['config' => $config, 'pages' => $pages, 'language' => $language, 'locator' => $locator]),
            $config,
        );
    }

    private function reorganize(array $operations): void
    {
        $body = ['operations' => $operations];
        $this->controller()->reorganize(TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/pages/reorganize',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user' => TestHelper::createMockUser('admin', ['access' => ['api' => ['super' => true]]]),
                'json_body' => $body,
            ],
        ));
    }

    #[Test]
    public function a_page_moves_out_of_a_folder_that_keeps_its_number_in_the_same_batch(): void
    {
        // The columns view payload from the report: it answered 200 and moved nothing.
        $this->reorganize([
            ['route' => '/parent/chapter', 'position' => 1],
            ['route' => '/parent/chapter/child2', 'parent' => '/parent', 'position' => 2],
            ['route' => '/parent/chapter2', 'position' => 3],
        ]);

        self::assertSame(['01.chapter', '02.child2', '03.chapter2'], $this->listing('parent'));
        self::assertSame(['01.child1'], $this->listing('parent/01.chapter'));
    }

    #[Test]
    public function a_child_renumbers_inside_a_folder_that_keeps_its_number_in_the_same_batch(): void
    {
        // The tree view payload for dropping child1 between chapter and chapter2:
        // it failed with "rename(...): No such file or directory".
        $this->reorganize([
            ['route' => '/parent/chapter/child1', 'parent' => '/parent', 'position' => 2],
            ['route' => '/parent/chapter/child2', 'position' => 1],
            ['route' => '/parent/chapter', 'position' => 1],
            ['route' => '/parent/chapter2', 'position' => 3],
        ]);

        self::assertSame(['01.chapter', '02.child1', '03.chapter2'], $this->listing('parent'));
        self::assertSame(['01.child2'], $this->listing('parent/01.chapter'));
    }

    #[Test]
    public function renumbering_inside_a_folder_the_batch_renames_is_refused_and_nothing_moves(): void
    {
        // The tree view payload from the report. The folder really is renamed
        // (01 to 02), so the op inside it has nowhere to land.
        try {
            $this->reorganize([
                ['route' => '/parent/chapter/child1', 'parent' => '/parent', 'position' => 1],
                ['route' => '/parent/chapter/child2', 'position' => 1],
                ['route' => '/parent/chapter', 'position' => 2],
                ['route' => '/parent/chapter2', 'position' => 3],
            ]);
            self::fail('reorganize() accepted an op inside a folder the same batch renames');
        } catch (ValidationException $e) {
            self::assertStringContainsString("'/parent/chapter'", $e->getMessage());
        }

        self::assertSame(['01.chapter', '02.chapter2'], $this->listing('parent'));
        self::assertSame(['01.child1', '02.child2'], $this->listing('parent/01.chapter'));
    }

    #[Test]
    public function the_batch_the_fixed_tree_view_sends_moves_the_child_up(): void
    {
        $this->reorganize([
            ['route' => '/parent/chapter/child1', 'parent' => '/parent', 'position' => 1],
            ['route' => '/parent/chapter', 'position' => 2],
            ['route' => '/parent/chapter2', 'position' => 3],
        ]);

        self::assertSame(['01.child1', '02.chapter', '03.chapter2'], $this->listing('parent'));
        self::assertSame(['02.child2'], $this->listing('parent/02.chapter'));
    }

    #[Test]
    public function a_page_moves_into_a_page_that_has_no_children(): void
    {
        $this->reorganize([
            ['route' => '/parent/chapter2', 'parent' => '/empty', 'position' => 1],
        ]);

        self::assertSame(['01.chapter2'], $this->listing('02.empty'));
        self::assertSame(['01.chapter'], $this->listing('parent'));
    }

    #[Test]
    public function a_page_moves_up_to_the_top_level(): void
    {
        $this->reorganize([
            ['route' => '/parent/chapter/child1', 'parent' => '/', 'position' => 1],
        ]);

        self::assertContains('01.child1', $this->listing(''));
        self::assertSame(['02.child2'], $this->listing('parent/01.chapter'));
    }

    #[Test]
    public function a_folder_with_sixty_children_moves_as_one_op_and_takes_them_along(): void
    {
        $this->reorganize([
            ['route' => '/sixty', 'parent' => '/parent', 'position' => 3],
        ]);

        self::assertSame(['01.chapter', '02.chapter2', '03.sixty'], $this->listing('parent'));
        self::assertCount(60, $this->listing('parent/03.sixty'));
        self::assertContains('60.c60', $this->listing('parent/03.sixty'));
        self::assertNotContains('03.sixty', $this->listing(''));
    }

    #[Test]
    public function an_unnumbered_page_moved_without_a_position_stays_unnumbered(): void
    {
        $this->reorganize([
            ['route' => '/mixed/c', 'parent' => '/parent/chapter'],
        ]);

        self::assertSame(['01.child1', '02.child2', 'c'], $this->listing('parent/01.chapter'));
        self::assertSame(['01.a', '02.b'], $this->listing('04.mixed'));
    }

    #[Test]
    public function a_page_landing_in_another_folder_takes_that_folders_number_width(): void
    {
        $this->reorganize([
            ['route' => '/parent/chapter/child1', 'parent' => '/wide', 'position' => 3],
        ]);

        self::assertSame(['001.w1', '002.w2', '003.child1'], $this->listing('05.wide'));
    }

    #[Test]
    public function a_page_staying_in_its_folder_keeps_its_own_number_width(): void
    {
        $this->reorganize([
            ['route' => '/wide/w1', 'position' => 3],
        ]);

        self::assertSame(['002.w2', '003.w1'], $this->listing('05.wide'));
    }

    #[Test]
    public function a_move_to_a_folder_that_already_has_the_slug_is_refused_and_nothing_moves(): void
    {
        try {
            $this->reorganize([
                ['route' => '/parent/chapter2', 'parent' => '/', 'position' => 8],
            ]);
            self::fail('reorganize() put a second chapter2 at the top level');
        } catch (ValidationException $e) {
            self::assertStringContainsString("'chapter2'", $e->getMessage());
        }

        self::assertSame(['01.chapter', '02.chapter2'], $this->listing('parent'));
        self::assertNotContains('08.chapter2', $this->listing(''));
    }
}
