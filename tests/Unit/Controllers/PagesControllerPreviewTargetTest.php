<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression guard for getgrav/grav-plugin-admin2#170.
 *
 * A module is not a page in its own right: it is a section the theme draws
 * inside its parent, and its rendered content is already the module template's
 * output. Dispatching one as the top-level page therefore runs that same
 * template a second time around its own output, which is why previewing a
 * module produced a bare, doubled fragment with no theme assets.
 *
 * The preview endpoint resolves the page the browser should actually load by
 * walking up the real hierarchy. It must never do that by trimming the route:
 * with `system.home.hide_in_urls` a child's route is missing its home segment,
 * so string-splitting lands on the wrong page (admin2#132).
 */
#[CoversClass(PagesController::class)]
class PagesControllerPreviewTargetTest extends TestCase
{
    /** @var array<string, PageInterface> */
    private array $pages = [];

    private function page(string $path, bool $isModule, ?string $parentPath = null, bool $isRoot = false): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn($path);
        $page->method('isModule')->willReturn($isModule);
        $page->method('root')->willReturn($isRoot);
        $page->method('parent')->willReturnCallback(
            fn (): ?PageInterface => $parentPath === null ? null : ($this->pages[$parentPath] ?? null)
        );

        $this->pages[$path] = $page;

        return $page;
    }

    private function target(PageInterface $page): PageInterface
    {
        $method = new ReflectionMethod(PagesController::class, 'previewRenderTarget');

        return $method->invoke(null, $page);
    }

    #[Test]
    public function anOrdinaryPagePreviewsAsItself(): void
    {
        $home = $this->page('/pages/01.home', false, null);

        self::assertSame($home, $this->target($home));
    }

    #[Test]
    public function aModulePreviewsAsTheParentItLivesIn(): void
    {
        $home = $this->page('/pages/01.home', false, null);
        $hero = $this->page('/pages/01.home/01._hero', true, '/pages/01.home');

        self::assertSame($home, $this->target($hero), 'a module renders inside its parent');
    }

    #[Test]
    public function aNestedModuleWalksUpToTheFirstOrdinaryPage(): void
    {
        $home = $this->page('/pages/01.home', false, null);
        $this->page('/pages/01.home/01._hero', true, '/pages/01.home');
        $inner = $this->page('/pages/01.home/01._hero/01._cta', true, '/pages/01.home/01._hero');

        self::assertSame($home, $this->target($inner), 'nesting must not stop at the enclosing module');
    }

    #[Test]
    public function aModuleWhoseParentIsTheRootStopsAtItself(): void
    {
        // Malformed, but the walk must terminate rather than hand back the
        // virtual root, which has no route to preview.
        $this->page('/pages', false, null, true);
        $orphan = $this->page('/pages/_orphan', true, '/pages');

        self::assertSame($orphan, $this->target($orphan));
    }

    #[Test]
    public function aCycleInTheTreeTerminates(): void
    {
        // Guards the `$seen` set: a parent chain that loops must not hang.
        $a = $this->page('/pages/a', true, '/pages/b');
        $this->page('/pages/b', true, '/pages/a');

        $target = $this->target($a);

        self::assertContains($target, [$this->pages['/pages/a'], $this->pages['/pages/b']]);
    }
}
