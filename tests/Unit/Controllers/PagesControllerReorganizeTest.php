<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PagesController::reorganize() validation logic.
 *
 * Tests exercise the validation phase without performing filesystem operations.
 */
#[CoversClass(PagesController::class)]
class PagesControllerReorganizeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_reorg_test_' . uniqid();
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
        @mkdir($this->tempDir . '/pages', 0775, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp dirs
        $this->rmrf($this->tempDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createController(array $knownPages = [], array $configOverrides = []): PagesController
    {
        $pagesService = $this->createPagesService($knownPages);
        $tempDir = $this->tempDir;

        $config = new Config(array_merge([
            'plugins' => ['api' => [
                'batch' => ['max_items' => 50],
                'route' => '/api',
                'version_prefix' => 'v1',
            ]],
        ], $configOverrides));

        $locator = new class ($tempDir) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false): ?string
            {
                return match (true) {
                    str_starts_with($uri, 'cache://') => $this->base . '/cache',
                    str_starts_with($uri, 'page://') => $this->base . '/pages',
                    default => $this->base,
                };
            }
        };

        $grav = TestHelper::createMockGrav([
            'pages' => $pagesService,
            'config' => $config,
            'locator' => $locator,
        ]);

        return new PagesController($grav, $config);
    }

    private function createPagesService(array $knownPages): object
    {
        return new class ($knownPages) {
            private array $pages;
            public function __construct(array $pages) { $this->pages = $pages; }
            public function enablePages(): void {}
            public function reset(): void {}
            public function find(string $route): ?object
            {
                return $this->pages[$route] ?? null;
            }
        };
    }

    private function createMockPage(string $route, string $slug, ?int $order = null): object
    {
        $pagesDir = $this->tempDir . '/pages';
        $path = $pagesDir . '/' . ($order !== null ? str_pad((string)$order, 2, '0', STR_PAD_LEFT) . '.' . $slug : $slug);

        return new class ($route, $slug, $order, $path) {
            public function __construct(
                private string $route,
                private string $slug,
                private ?int $order,
                private string $path,
            ) {}
            public function route($var = null): ?string { return $this->route; }
            public function slug($var = null): string { return $this->slug; }
            public function order($var = null): ?int { return $this->order; }
            public function path($var = null): ?string { return $this->path; }
            public function title($var = null): string { return ucfirst($this->slug); }
            public function isModule(): bool { return false; }
            public function children(): \Traversable { return new \ArrayIterator([]); }
        };
    }

    private function makeRequest(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $superAdmin = TestHelper::createMockUser('admin', [
            'access.admin.super' => true,
        ]);

        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/pages/reorganize',
            headers: ['Content-Type' => 'application/json'],
            body: json_encode($body),
            attributes: [
                'api_user' => $superAdmin,
                'json_body' => $body,
            ],
        );
    }

    // -------------------------------------------------------
    // Validation tests
    // -------------------------------------------------------

    #[Test]
    public function reorganize_requires_operations_field(): void
    {
        $controller = $this->createController();

        $this->expectException(ValidationException::class);
        $controller->reorganize($this->makeRequest([]));
    }

    #[Test]
    public function reorganize_rejects_empty_operations_array(): void
    {
        $controller = $this->createController();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('non-empty array');
        $controller->reorganize($this->makeRequest(['operations' => []]));
    }

    #[Test]
    public function reorganize_rejects_operation_missing_route(): void
    {
        $controller = $this->createController();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('route');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['position' => 1],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_duplicate_routes(): void
    {
        $controller = $this->createController([
            '/blog/post-a' => $this->createMockPage('/blog/post-a', 'post-a'),
            '/blog' => $this->createMockPage('/blog', 'blog'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['route' => '/blog/post-a', 'position' => 1],
                ['route' => '/blog/post-a', 'position' => 2],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_nonexistent_page(): void
    {
        $controller = $this->createController();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not found');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['route' => '/does-not-exist', 'position' => 1],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_nonexistent_parent(): void
    {
        $controller = $this->createController([
            '/blog/post-a' => $this->createMockPage('/blog/post-a', 'post-a'),
            '/blog' => $this->createMockPage('/blog', 'blog'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Destination parent not found');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['route' => '/blog/post-a', 'parent' => '/nonexistent', 'position' => 1],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_move_into_own_subtree(): void
    {
        $controller = $this->createController([
            '/blog' => $this->createMockPage('/blog', 'blog'),
            '/blog/child' => $this->createMockPage('/blog/child', 'child'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('own subtree');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['route' => '/blog', 'parent' => '/blog/child'],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_position_conflict(): void
    {
        $controller = $this->createController([
            '/blog/post-a' => $this->createMockPage('/blog/post-a', 'post-a'),
            '/blog/post-b' => $this->createMockPage('/blog/post-b', 'post-b'),
            '/blog' => $this->createMockPage('/blog', 'blog'),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Position conflict');
        $controller->reorganize($this->makeRequest([
            'operations' => [
                ['route' => '/blog/post-a', 'position' => 1],
                ['route' => '/blog/post-b', 'position' => 1],
            ],
        ]));
    }

    #[Test]
    public function reorganize_rejects_exceeding_batch_limit(): void
    {
        $pages = [];
        $ops = [];
        for ($i = 0; $i < 51; $i++) {
            $route = "/page-{$i}";
            $pages[$route] = $this->createMockPage($route, "page-{$i}");
            $ops[] = ['route' => $route, 'position' => $i + 1];
        }

        $controller = $this->createController($pages);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limited to');
        $controller->reorganize($this->makeRequest(['operations' => $ops]));
    }
}
