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
 * `slug:` is rewritten to the copy's own slug (#25); `routes.default` is
 * dropped, because Page::route() returns it before looking at the folder or the
 * slug, so the copy would answer to the source's address (#27).
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
    public function drops_routes_default_and_keeps_aliases_and_canonical(): void
    {
        $path = $this->writePage('default.md', <<<YAML
title: Test
routes:
    default: /a-fixed-address
    canonical: /a-fixed-address
    aliases:
        - /old-address
YAML . "\n");

        $this->rewrite('test-copy');

        $header = $this->header($path);
        $this->assertSame('Test', $header['title']);
        $this->assertArrayNotHasKey('slug', $header, 'A page without a slug must not gain one.');
        $this->assertArrayNotHasKey('default', $header['routes']);
        $this->assertSame('/a-fixed-address', $header['routes']['canonical']);
        $this->assertSame(['/old-address'], $header['routes']['aliases']);
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
    public function leaves_a_page_without_slug_or_routes_default_untouched(): void
    {
        $raw = "---\ntitle: Test\nroutes:\n    aliases:\n        - /old\n---\n\nBody\n";
        $path = $this->tmp . '/default.md';
        file_put_contents($path, $raw);

        $this->rewrite('test-copy');

        $this->assertSame($raw, file_get_contents($path));
    }
}
