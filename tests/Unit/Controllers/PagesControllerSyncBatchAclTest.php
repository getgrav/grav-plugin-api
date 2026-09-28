<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Two endpoints reached a page without the page-level rule the endpoint it
 * mirrors applies:
 *
 *  - POST /pages/{route}/sync authorized the SOURCE translation and then wrote
 *    the TARGET, so a translation whose own frontmatter denies `update` was
 *    overwritten from another language, permissions block included.
 *  - POST /pages/batch {operation: copy} never checked the destination's
 *    `create` rule, which POST /pages/{route}/copy, move and reorganize do.
 */
class PagesControllerSyncBatchAclTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/grav_api_sync_batch_acl_' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/locked', 0777, true);
        file_put_contents($this->root . '/src/default.md', "---\ntitle: Source\n---\nBody\n");
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
        Grav::resetInstance();
    }

    private function editor(): UserInterface
    {
        return TestHelper::createMockUser('ed', [
            'username' => 'ed',
            'groups' => ['editors'],
            'access' => ['api' => ['access' => true, 'pages' => ['read' => true, 'write' => true]]],
        ]);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function page(string $route, string $path, array $header = [], ?\Closure $onSave = null): PageInterface
    {
        $page = $this->createMock(SyncBatchAclTestPage::class);
        $page->method('header')->willReturn(json_decode((string) json_encode($header ?: ['title' => $route])));
        $page->method('rawRoute')->willReturn($route);
        $page->method('route')->willReturn($route);
        $page->method('slug')->willReturn(basename($route));
        $page->method('path')->willReturn($path);
        $page->method('parent')->willReturn(null);
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('language')->willReturn(null);
        $page->method('rawMarkdown')->willReturn('Body');
        $page->method('translatedLanguages')->willReturn(['en' => $route, 'fr' => $route]);
        if ($onSave !== null) {
            $page->method('save')->willReturnCallback($onSave);
        }

        return $page;
    }

    /**
     * @param array<string, PageInterface|array<string, PageInterface>> $routes route => page, or route => [lang => page]
     */
    private function controller(array $routes): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);

        $language = new class {
            public ?string $active = 'en';
            public function getActive(): ?string { return $this->active; }
            public function setActive($lang): void { $this->active = $lang; }
            public function resetFallbackPageExtensions(): void {}
            public function enabled(): bool { return true; }
            public function validate($lang): bool { return in_array($lang, ['en', 'fr'], true); }
            public function getLanguages(): array { return ['en', 'fr']; }
            public function getDefault(): string { return 'en'; }
        };

        $pages = new class ($routes, $language) {
            public function __construct(private array $routes, private object $language) {}
            public function reset(): void {}
            public function enablePages(): void {}
            public function markChanged(): void {}
            public function root(): ?PageInterface { return null; }
            public function find(string $route): ?PageInterface
            {
                $entry = $this->routes[$route] ?? null;

                return is_array($entry) ? ($entry[$this->language->getActive()] ?? null) : $entry;
            }
        };

        $locator = new class ($this->root) {
            public function __construct(private string $root) {}
            public function findResource(string $uri, bool $absolute = false): string { return $this->root; }
        };

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'language' => $language,
            'pages' => $pages,
            'locator' => $locator,
            'events' => new class {
                public function dispatch(object $event, ?string $eventName = null): object { return $event; }
            },
            'debugger' => new class {
                public function enabled(): bool { return false; }
            },
        ]);

        return new PagesController($grav, $config);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body, string $route = ''): ServerRequestInterface
    {
        return TestHelper::createMockRequest(
            method: 'POST',
            attributes: ['api_user' => $this->editor(), 'json_body' => $body, 'route_params' => ['route' => $route]],
        );
    }

    #[Test]
    public function sync_refuses_a_target_translation_that_denies_update(): void
    {
        $source = $this->page('/secret', $this->root . '/src');
        $target = $this->page(
            '/secret',
            $this->root . '/src',
            ['title' => 'Secret FR', 'permissions' => ['groups' => ['editors' => '-u']]],
            static fn () => throw new \LogicException('the denied target translation was written'),
        );

        $controller = $this->controller(['/secret' => ['en' => $source, 'fr' => $target]]);

        $this->expectException(ForbiddenException::class);
        $controller->sync($this->request(['source_lang' => 'en', 'target_lang' => 'fr'], 'secret'));
    }

    #[Test]
    public function sync_still_reaches_a_target_with_no_rule(): void
    {
        $source = $this->page('/open', $this->root . '/src');
        $target = $this->page(
            '/open',
            $this->root . '/src',
            ['title' => 'Open FR'],
            static fn () => throw new \LogicException('saved'),
        );

        $controller = $this->controller(['/open' => ['en' => $source, 'fr' => $target]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('saved');
        $controller->sync($this->request(['source_lang' => 'en', 'target_lang' => 'fr'], 'open'));
    }

    #[Test]
    public function batch_copy_refuses_a_destination_that_denies_create(): void
    {
        $src = $this->page('/src', $this->root . '/src');
        $locked = $this->page('/locked', $this->root . '/locked', [
            'title' => 'Locked',
            'permissions' => ['groups' => ['editors' => '-c']],
        ]);

        $controller = $this->controller(['/src' => $src, '/locked' => $locked]);
        $response = $controller->batch($this->request([
            'operation' => 'copy',
            'routes' => ['/src'],
            'options' => ['destination' => '/locked'],
        ]));

        $data = json_decode((string) $response->getBody(), true)['data'];

        self::assertSame('error', $data['results'][0]['status']);
        self::assertStringContainsString("deny 'create'", $data['results'][0]['message']);
        self::assertDirectoryDoesNotExist($this->root . '/locked/src-copy');
    }
}

/**
 * The page methods sync reads and writes, which the standalone PageInterface
 * stub leaves out. Declared here rather than on the stub so the test doubles
 * elsewhere that implement PageInterface are unaffected.
 */
interface SyncBatchAclTestPage extends PageInterface
{
    public function rawMarkdown($var = null);
    public function translatedLanguages($onlyPublished = false);
    public function save($reorder = true);
}
