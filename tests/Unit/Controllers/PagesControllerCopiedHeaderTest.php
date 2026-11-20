<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Tests\Unit\Services\I18nTestFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RocketTheme\Toolbox\File\MarkdownFile;

/**
 * A copied page must not keep frontmatter that pins it to the source's route.
 * `slug:` is rewritten to the copy's own slug (#25). Every `routes` address is
 * dropped from the copy and the pages under it: `routes.default` replaces the
 * route outright, and `canonical` and the aliases go into the same route map,
 * so a copy that kept any of them would still claim the source's address (#27).
 */
#[CoversClass(PagesController::class)]
class PagesControllerCopiedHeaderTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        if (!class_exists(MarkdownFile::class)) {
            $this->markTestSkipped('Needs Grav core (RocketTheme Toolbox) on the autoloader.');
        }

        $this->tmp = sys_get_temp_dir() . '/grav-api-copy-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            I18nTestFixture::rrmdir($this->tmp);
        }
    }

    private function writePage(string $filename, string $frontmatter): string
    {
        $path = $this->tmp . '/' . $filename;
        file_put_contents($path, "---\n" . $frontmatter . "---\n\nBody\n");

        return $path;
    }

    private function rewrite(string $destSlug, string $extension = '.md'): void
    {
        $controller = (new ReflectionClass(PagesController::class))->newInstanceWithoutConstructor();
        (new ReflectionMethod(PagesController::class, 'rewriteCopiedSlug'))
            ->invoke($controller, $this->tmp, $destSlug, $extension);
    }

    /** @return array<string, mixed> */
    private function header(string $path): array
    {
        $file = MarkdownFile::instance($path);
        try {
            return (array) $file->header();
        } finally {
            $file->free();
        }
    }

    #[Test]
    public function drops_every_routes_address_and_keeps_other_keys(): void
    {
        $path = $this->writePage('default.md', <<<YAML
title: Test
routes:
    default: /a-fixed-address
    canonical: /a-fixed-address
    aliases:
        - /old-address
    custom: kept
YAML . "\n");

        $this->rewrite('test-copy');

        $header = $this->header($path);
        $this->assertSame('Test', $header['title']);
        $this->assertArrayNotHasKey('slug', $header, 'A page without a slug must not gain one.');
        $this->assertSame(['custom' => 'kept'], $header['routes']);
    }

    #[Test]
    public function drops_aliases_and_canonical_without_a_default(): void
    {
        $path = $this->writePage('default.md', "title: Test\nroutes:\n    canonical: /src\n    aliases:\n        - /old\n");

        $this->rewrite('test-copy');

        $this->assertArrayNotHasKey('routes', $this->header($path));
    }

    #[Test]
    public function drops_routes_from_pages_below_the_copy_but_keeps_their_slugs(): void
    {
        mkdir($this->tmp . '/01.child/02.grandchild', 0777, true);
        $this->writePage('default.md', "title: Test\n");
        $child = $this->writePage('01.child/default.md', "title: Child\nslug: child\nroutes:\n    default: /child-fixed\n");
        $grandchild = $this->writePage('01.child/02.grandchild/default.de.md', "title: Enkel\nroutes:\n    aliases:\n        - /enkel\n");

        $this->rewrite('test-copy');

        $header = $this->header($child);
        $this->assertSame('child', $header['slug']);
        $this->assertArrayNotHasKey('routes', $header);
        $this->assertArrayNotHasKey('routes', $this->header($grandchild));
    }

    #[Test]
    public function removes_the_routes_block_when_default_was_its_only_entry(): void
    {
        $path = $this->writePage('default.md', "title: Test\nslug: test\nroutes:\n    default: /a-fixed-address\n");

        $this->rewrite('test-copy');

        $header = $this->header($path);
        $this->assertSame('test-copy', $header['slug']);
        $this->assertArrayNotHasKey('routes', $header);
    }

    #[Test]
    public function drops_routes_default_from_every_language_file(): void
    {
        $en = $this->writePage('default.en.md', "title: Test\nroutes:\n    default: /fixed\n");
        $de = $this->writePage('default.de.md', "title: Test DE\nroutes:\n    default: /fest\n");

        $this->rewrite('test-copy', '.en.md');

        $this->assertArrayNotHasKey('routes', $this->header($en));
        $this->assertArrayNotHasKey('routes', $this->header($de));
    }

    #[Test]
    public function leaves_a_page_without_slug_or_routes_untouched(): void
    {
        $raw = "---\n# kept as written\ntitle: 'Test'\ntaxonomy: { tag: [a] }\n---\n\nBody\n";
        $path = $this->tmp . '/default.md';
        file_put_contents($path, $raw);

        $this->rewrite('test-copy');

        $this->assertSame($raw, file_get_contents($path));
    }
}
