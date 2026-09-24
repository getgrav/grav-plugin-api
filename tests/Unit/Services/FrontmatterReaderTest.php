<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Services\FrontmatterReader;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Flex-indexed page listings read each row's frontmatter from disk; the reader
 * parses a file once and remembers it until the file changes.
 */
#[CoversClass(FrontmatterReader::class)]
class FrontmatterReaderTest extends TestCase
{
    private string $dir;
    private I18nMemoryCache $cache;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-frontmatter-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        $this->cache = new I18nMemoryCache();
        TestHelper::createMockGrav(['cache' => $this->cache]);
        FrontmatterReader::reset();
    }

    protected function tearDown(): void
    {
        FrontmatterReader::reset();
        Grav::resetInstance();
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function parses_the_frontmatter_block(): void
    {
        $file = $this->write('default.md', "---\ntitle: Hello\npublished: false\n---\n\nBody with ---\n");

        self::assertSame(['title' => 'Hello', 'published' => false], FrontmatterReader::parse($file));
    }

    #[Test]
    public function files_without_usable_frontmatter_read_as_empty(): void
    {
        self::assertSame([], FrontmatterReader::parse($this->write('a.md', "No frontmatter\n")));
        self::assertSame([], FrontmatterReader::parse($this->write('b.md', "---\n[not: valid: yaml\n---\n")));
        self::assertSame([], FrontmatterReader::parse($this->write('c.md', "---\njust a string\n---\n")));
        self::assertSame([], FrontmatterReader::parse($this->dir . '/missing.md'));
    }

    #[Test]
    public function a_second_request_reads_the_grav_cache_instead_of_parsing(): void
    {
        $file = $this->write('default.md', "---\ntitle: Cached\n---\n");
        FrontmatterReader::parse($file);

        // Same file, same mtime/size/inode: a new request (fresh memo) gets the
        // cached header. Tampering with the cache entry proves it was used.
        $key = $this->cache->keysStartingWith('api-frontmatter-')[0];
        $entry = $this->cache->fetch($key);
        $entry[1] = ['title' => 'From cache'];
        $this->cache->save($key, $entry);
        FrontmatterReader::reset();

        self::assertSame(['title' => 'From cache'], FrontmatterReader::parse($file));
    }

    #[Test]
    public function an_edited_file_is_parsed_again(): void
    {
        $file = $this->write('default.md', "---\ntitle: Before\n---\n");
        self::assertSame(['title' => 'Before'], FrontmatterReader::parse($file));

        // Same size, same second: the rename a normal save does changes the
        // inode, so the old header isn't served.
        $tmp = $this->write('default.md.tmp', "---\ntitle: Aft3r!\n---\n");
        rename($tmp, $file);
        clearstatcache();

        self::assertSame(['title' => 'Aft3r!'], FrontmatterReader::parse($file));

        FrontmatterReader::reset();
        self::assertSame(['title' => 'Aft3r!'], FrontmatterReader::parse($file));
    }

    #[Test]
    public function a_page_reads_its_language_file_first_then_the_untyped_one_then_any_other(): void
    {
        $this->write('default.fr.md', "---\ntitle: Bonjour\n---\n");
        $this->write('default.md', "---\ntitle: Hello\n---\n");
        $this->write('default.de.md', "---\ntitle: Hallo\n---\n");

        self::assertSame('Bonjour', FrontmatterReader::forPage($this->page('fr'))['title']);
        self::assertSame('Hello', FrontmatterReader::forPage($this->page(null))['title']);
        self::assertSame('Hallo', FrontmatterReader::forPage($this->page(null), 'de')['title']);
        self::assertSame('Hello', FrontmatterReader::forPage($this->page('es'))['title']);

        unlink($this->dir . '/default.md');
        // Nothing for the page's language or untyped: the first {template}*.md
        // with frontmatter, in glob order.
        self::assertSame('Hallo', FrontmatterReader::forPage($this->page('es'))['title']);
    }

    #[Test]
    public function a_page_without_path_or_template_has_no_header(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn(null);
        $page->method('template')->willReturn('default');

        self::assertSame([], FrontmatterReader::forPage($page));
    }

    #[Test]
    public function works_without_a_grav_cache(): void
    {
        TestHelper::createMockGrav();
        $file = $this->write('default.md', "---\ntitle: No cache\n---\n");

        self::assertSame(['title' => 'No cache'], FrontmatterReader::parse($file));
    }

    private function page(?string $language): PageInterface
    {
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn($this->dir);
        $page->method('template')->willReturn('default');
        $page->method('language')->willReturn($language);

        return $page;
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
