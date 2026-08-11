<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Plugin\Api\Controllers\PagesController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for PagesController::rewriteCopiedSlug().
 *
 * Regression coverage for #25 / getgrav/grav-plugin-admin2#154 — copying a page
 * that declares its own `slug:` produced two pages claiming the same route,
 * because Folder::copy() reproduces the frontmatter byte for byte and nothing
 * rewrote it afterwards.
 *
 * Grav resolves a page's route as parent route + slug(), and slug() prefers the
 * header value over the folder name, so the copy answered to the source's
 * address. Pages::buildRoutes() only logs the clash and lets the last-indexed
 * page win, which meant the admin page tree aborted on the duplicate key and the
 * title update sent straight after a copy could land on the original page.
 */
#[CoversClass(PagesController::class)]
class PagesControllerCopySlugTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/grav-api-copyslug-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function rewrite(string $slug, string $extension = '.md'): void
    {
        $ref = new ReflectionClass(PagesController::class);
        $instance = $ref->newInstanceWithoutConstructor();
        $ref->getMethod('rewriteCopiedSlug')->invoke($instance, $this->dir, $slug, $extension);
    }

    private function write(string $name, string $body): void
    {
        file_put_contents($this->dir . '/' . $name, $body);
    }

    private function read(string $name): string
    {
        return (string) file_get_contents($this->dir . '/' . $name);
    }

    #[Test]
    public function explicit_slug_is_pointed_at_the_new_folder(): void
    {
        $this->write('default.md', "---\ntitle: Test Page\nslug: im-a-test\n---\n\nHello, world\n");

        $this->rewrite('im-a-test-2');

        $out = $this->read('default.md');
        self::assertStringContainsString('slug: im-a-test-2', $out);
        self::assertStringNotContainsString('slug: im-a-test' . "\n", $out);
        self::assertStringContainsString('Hello, world', $out, 'page body must survive the rewrite');
        // Note the frontmatter is re-serialized, so YAML may re-quote a value
        // ("Test Page" becomes 'Test Page'). That is the same normalization
        // $page->save() performs in admin-classic, and only happens for a page
        // that actually declared a slug.
        self::assertMatchesRegularExpression("/title: '?Test Page'?/", $out, 'other frontmatter must survive');
    }

    #[Test]
    public function every_language_variant_is_rewritten(): void
    {
        foreach (['default.md', 'default.de.md', 'default.fr.md'] as $file) {
            $this->write($file, "---\ntitle: T\nslug: shared\n---\n\nbody\n");
        }

        $this->rewrite('shared-2');

        foreach (['default.md', 'default.de.md', 'default.fr.md'] as $file) {
            self::assertStringContainsString(
                'slug: shared-2',
                $this->read($file),
                "{$file} keeps the source slug and would still collide"
            );
        }
    }

    #[Test]
    public function a_page_without_a_slug_does_not_gain_one(): void
    {
        $this->write('default.md', "---\ntitle: No Slug Here\n---\n\nbody\n");

        $this->rewrite('somewhere-2');

        $out = $this->read('default.md');
        self::assertStringNotContainsString('slug:', $out, 'we must not add frontmatter the source did not have');
        self::assertStringContainsString('title: No Slug Here', $out);
    }

    #[Test]
    public function unrelated_frontmatter_is_preserved(): void
    {
        // external_url and routes travel with a copy too. They are deliberately
        // left alone: external_url takes no part in routing, and silently
        // dropping routes.aliases would destroy something the author typed.
        $this->write('default.md', <<<'MD'
---
title: Linked
slug: original
external_url: 'https://example.com'
routes:
    aliases:
        - /old-address
taxonomy:
    tag: [one, two]
---

body
MD);

        $this->rewrite('original-2');

        $out = $this->read('default.md');
        self::assertStringContainsString('slug: original-2', $out);
        self::assertStringContainsString('example.com', $out);
        self::assertStringContainsString('/old-address', $out);
        self::assertStringContainsString('one', $out);
    }

    #[Test]
    public function non_page_files_in_the_folder_are_ignored(): void
    {
        $this->write('default.md', "---\ntitle: T\nslug: keep\n---\n\nbody\n");
        $this->write('notes.txt', "slug: keep\n");
        $this->write('photo.jpg.meta.yaml', "slug: keep\n");

        $this->rewrite('keep-2');

        self::assertStringContainsString('slug: keep-2', $this->read('default.md'));
        self::assertSame("slug: keep\n", $this->read('notes.txt'));
        self::assertSame("slug: keep\n", $this->read('photo.jpg.meta.yaml'));
    }

    #[Test]
    public function extension_is_honoured_with_or_without_a_leading_dot(): void
    {
        $this->write('default.md', "---\ntitle: T\nslug: a\n---\n\nbody\n");
        $this->rewrite('b', 'md');

        self::assertStringContainsString('slug: b', $this->read('default.md'));
    }
}
