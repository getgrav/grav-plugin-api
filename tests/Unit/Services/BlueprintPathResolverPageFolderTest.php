<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Plugin\Api\Services\BlueprintPathResolver;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * logicalParent() for a page scope must name the page's real folder.
 *
 * A page's route drops the folder's numeric order prefix (`01.home` is served
 * at `/home`), so a `self@` upload on the home page was written to
 * user/pages/01.home/ but reported back as user/pages/home/<file>. That stored
 * path pointed at a folder that doesn't exist, which broke the admin thumbnail
 * and any later delete check against the field's folder.
 */
#[CoversClass(BlueprintPathResolver::class)]
class BlueprintPathResolverPageFolderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/bpr-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/user/pages/01.home', 0777, true);
        mkdir($this->root . '/user/pages/02.blog/03.my-post', 0777, true);
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
        }
        rmdir($this->root);
    }

    private function resolver(array $routes): BlueprintPathResolver
    {
        $user = $this->root . '/user';
        $locator = new class ($user) {
            public function __construct(private string $user) {}
            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                return $uri === 'user://' ? $this->user : false;
            }
            public function isStream(string $uri): bool
            {
                return str_contains($uri, '://');
            }
        };
        $pages = new class ($routes, $user) {
            public function __construct(private array $routes, private string $user) {}
            public function find(string $route): ?object
            {
                $folder = $this->routes[$route] ?? null;
                if ($folder === null) {
                    return null;
                }
                $path = $this->user . '/pages/' . $folder;
                return new class ($path) {
                    public function __construct(private string $path) {}
                    public function path(): string { return $this->path; }
                };
            }
        };

        return new BlueprintPathResolver(TestHelper::createMockGrav(['locator' => $locator, 'pages' => $pages]));
    }

    #[Test]
    public function self_destination_on_a_page_uses_the_numbered_folder(): void
    {
        $resolver = $this->resolver(['/home' => '01.home']);

        $this->assertSame('pages/01.home', $resolver->logicalParent('self@', 'pages/home'));
    }

    #[Test]
    public function nested_pages_and_subpaths_keep_every_folder_prefix(): void
    {
        $resolver = $this->resolver(['/blog/my-post' => '02.blog/03.my-post']);

        $this->assertSame(
            'pages/02.blog/03.my-post/images',
            $resolver->logicalParent('self@:images', 'pages/blog/my-post')
        );
    }

    #[Test]
    public function an_unknown_page_falls_back_to_the_route_form(): void
    {
        $resolver = $this->resolver([]);

        $this->assertSame('pages/missing', $resolver->logicalParent('self@', 'pages/missing'));
    }
}
