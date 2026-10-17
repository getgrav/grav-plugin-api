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
 * Regression coverage for getgrav/grav-admin-next#40: move, reorganize and
 * reorder named the renamed folder after the page's slug(), which honours a
 * `slug:` frontmatter override, instead of after the folder itself.
 */
#[CoversClass(PagesController::class)]
class PagesControllerFolderNameTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-foldername-' . bin2hex(random_bytes(6));
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

    /** @param array<string, string> $slugs header `slug:` overrides, keyed by folder */
    private function parentPage(string $path, array $childFolders, array $slugs = []): PageInterface
    {
        $children = [];
        foreach ($childFolders as $folder) {
            $slug = $slugs[$folder] ?? preg_replace('/^\d+\./', '', $folder);
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

    private function pageAt(string $route, string $slug, string $path, ?int $order = null): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('route')->willReturn($route);
        $page->method('rawRoute')->willReturn($route);
        $page->method('slug')->willReturn($slug);
        $page->method('path')->willReturn($path);
        $page->method('order')->willReturn($order);
        $page->method('header')->willReturn((object) ['title' => $slug, 'slug' => $slug]);
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('parent')->willReturn(null);

        return $page;
    }

    // A `slug:` header changes the route, never the folder. Every endpoint that
    // renames a page folder used to rebuild the name from slug(), so renumbering
    // `02.typography` (slug: my-slug) left it as `NN.my-slug`.

    #[Test]
    public function move_without_a_slug_keeps_the_folder_name_not_the_header_slug(): void
    {
        $this->folders(['01.sibling', '02.typography']);
        $page = $this->pageAt('/parent/my-slug', 'my-slug', $this->dir . '/parent/02.typography', 2);
        $controller = $this->controller(
            $this->parentPage($this->dir . '/parent', ['01.sibling', '02.typography'], ['02.typography' => 'my-slug']),
            null,
            ['/parent/my-slug' => $page],
        );

        try {
            $controller->move($this->request(['parent' => '/parent', 'order' => 5], ['route' => 'parent/my-slug']));
        } catch (\Error) {
            // Unfixed code renames to `05.my-slug`, finds the mock again on its
            // old route and fails serializing it; the folder is what matters here.
        }

        self::assertSame(['01.sibling', '05.typography'], $this->listing());
    }

    #[Test]
    public function reorganize_keeps_the_folder_name_not_the_header_slug(): void
    {
        $this->folders(['01.sibling', '02.typography']);
        $controller = $this->controller(
            $this->parentPage($this->dir . '/parent', ['01.sibling', '02.typography'], ['02.typography' => 'my-slug']),
            null,
            ['/parent/my-slug' => $this->pageAt('/parent/my-slug', 'my-slug', $this->dir . '/parent/02.typography', 2)],
        );

        $controller->reorganize($this->request(['operations' => [
            ['route' => '/parent/my-slug', 'position' => 3],
        ]]));

        self::assertSame(['01.sibling', '03.typography'], $this->listing());
    }

    #[Test]
    public function reorder_keeps_the_folder_name_not_the_header_slug(): void
    {
        $this->folders(['01.sibling', '02.typography']);
        $controller = $this->controller(
            $this->parentPage($this->dir . '/parent', ['01.sibling', '02.typography'], ['02.typography' => 'my-slug']),
        );

        $controller->reorder($this->request(['order' => ['my-slug', 'sibling']], ['route' => 'parent']));

        self::assertSame(['01.typography', '02.sibling'], $this->listing());
    }
}
