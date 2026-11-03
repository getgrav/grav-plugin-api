<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Framework\Flex\FlexIndex;
use Grav\Plugin\Api\PageAcl;
use Grav\Plugin\Api\PageAclIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An API page write hands the cached page-rules index over to the new state
 * instead of dropping it (getgrav/grav-plugin-admin2#209), so the first
 * listing after a save doesn't read every page's frontmatter again. Anything
 * short of a sure result leaves the index to be rebuilt, and an edit made on
 * disk moves the checksum the cache key follows.
 */
#[CoversClass(PageAclIndex::class)]
class PageAclIndexRefreshTest extends TestCase
{
    use BuildsPageAclTree;

    /** @var array<string, mixed> */
    private array $store = [];

    private string $checksum = 'c1';

    private string $language = '';

    private ?FlexDirectory $directory = null;

    /** @var list<string> the flex keys loaded since the last reset */
    private array $loaded = [];

    private Grav $grav;

    protected function setUp(): void
    {
        $this->store = [];
        $this->checksum = 'c1';
        $this->language = '';
        $this->loaded = [];

        $store = &$this->store;
        $language = &$this->language;
        $this->grav = TestHelper::createMockGrav([
            'cache' => new class ($store) {
                /** @param array<string, mixed> $store */
                public function __construct(private array &$store) {}

                public function fetch(string $key): mixed
                {
                    return $this->store[$key] ?? false;
                }

                public function save(string $key, mixed $value): bool
                {
                    $this->store[$key] = $value;

                    return true;
                }
            },
            'language' => new class ($language) {
                public function __construct(private string &$language) {}

                public function getActive(): ?string
                {
                    return $this->language ?: null;
                }
            },
            'locator' => new class {
                public function findResource(string $uri, bool $absolute = false): string
                {
                    return sys_get_temp_dir() . '/grav_api_acl_refresh_none';
                }
            },
        ]);
    }

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /** The Flex pages directory over the current mock tree, at the current checksum. */
    private function directory(): FlexDirectory
    {
        $entries = [];
        foreach ($this->tree as $route => $page) {
            if (!$page->root()) {
                $entries[$route] = ['storage_key' => ltrim(PageAclIndex::keyOf($page), '/'), 'checksum' => 'p-' . $route];
            }
        }

        $index = $this->createMock(FlexIndex::class);
        $index->method('getCacheChecksum')->willReturnCallback(fn (): string => $this->checksum);
        $index->method('getIndexMap')->willReturn($entries);
        $index->method('get')->willReturnCallback(function (string $route): ?PageInterface {
            $this->loaded[] = $route;

            return $this->tree[$route] ?? null;
        });
        $index->method('getIterator')->willReturnCallback(fn (): \ArrayIterator => new \ArrayIterator(
            array_filter($this->tree, static fn (PageInterface $page): bool => !$page->root()),
        ));

        $directory = $this->createMock(FlexDirectory::class);
        $directory->method('getIndex')->willReturn($index);
        $directory->method('getCollection')->willReturn($index);

        return $directory;
    }

    private function generation(): ?string
    {
        return PageAclIndex::invalidate($this->grav);
    }

    #[Test]
    public function a_refreshed_index_is_what_the_next_listing_loads(): void
    {
        $this->buildTree();
        $acl = new PageAcl();
        $directory = $this->directory();

        PageAclIndex::load($this->grav, $directory, $acl);
        $snapshot = PageAclIndex::snapshot($this->grav, $directory);
        self::assertNotNull($snapshot);

        // A page is added, the write bumps the stamp and the checksum.
        $generation = $this->generation();
        $this->treePage('/outils/nouveau', ['title' => 'nouveau']);
        $this->checksum = 'c2';
        $directory = $this->directory();

        $this->loaded = [];
        $stored = PageAclIndex::refresh($this->grav, $directory, $snapshot, $generation, [], ['/p/outils/nouveau']);
        self::assertTrue($stored);
        // Only the new page was read.
        self::assertSame(['/outils/nouveau'], $this->loaded);

        // The next listing finds it under the new checksum and stamp: it
        // builds nothing (an index that can't be iterated proves it).
        $index = $this->createMock(FlexIndex::class);
        $index->method('getCacheChecksum')->willReturn('c2');
        $index->method('getIterator')->willThrowException(new \LogicException('The index was built again.'));
        $second = $this->createMock(FlexDirectory::class);
        $second->method('getIndex')->willReturn($index);
        $second->method('getCollection')->willReturn($index);

        $loaded = PageAclIndex::load($this->grav, $second, $acl);

        self::assertTrue($loaded->has('/p/outils/nouveau'));
        self::assertSame('/p/outils', $loaded->parentOf('/p/outils/nouveau'));
        self::assertContains('/p/outils/nouveau', $loaded->listableKeys());
    }

    #[Test]
    public function without_a_refresh_the_next_listing_builds_a_new_index(): void
    {
        $this->buildTree();
        $acl = new PageAcl();
        PageAclIndex::load($this->grav, $this->directory(), $acl);

        // invalidate() alone, as before: the old entry is no longer found.
        $this->generation();

        self::assertNull(PageAclIndex::snapshot($this->grav, $this->directory()));
    }

    #[Test]
    public function an_edit_on_disk_moves_the_checksum_and_is_not_read_from_the_old_index(): void
    {
        $this->buildTree();
        $acl = new PageAcl();
        $directory = $this->directory();
        PageAclIndex::load($this->grav, $directory, $acl);
        self::assertNotNull(PageAclIndex::snapshot($this->grav, $directory));

        $this->checksum = 'edited';

        self::assertNull(PageAclIndex::snapshot($this->grav, $this->directory()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusals(): iterable
    {
        yield 'another write happened meanwhile' => ['generation'];
        yield 'the language changed' => ['language'];
        yield 'there was no cached index to start from' => ['snapshot'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function a_refresh_it_cannot_vouch_for_stores_nothing(string $reason): void
    {
        $this->buildTree();
        $acl = new PageAcl();
        $directory = $this->directory();
        PageAclIndex::load($this->grav, $directory, $acl);
        $snapshot = PageAclIndex::snapshot($this->grav, $directory);

        $generation = $this->generation();
        $this->checksum = 'c2';
        $cached = static fn (array $store): array => array_keys(array_filter(
            $store,
            static fn (mixed $value, string $key): bool => str_starts_with($key, 'api-page-acl-index-v'),
            ARRAY_FILTER_USE_BOTH,
        ));
        $before = $cached($this->store);

        if ($reason === 'generation') {
            $this->generation();
        } elseif ($reason === 'language') {
            $this->language = 'fr';
        } else {
            $snapshot = null;
        }

        self::assertFalse(PageAclIndex::refresh($this->grav, $this->directory(), $snapshot, $generation, [], []));
        self::assertSame($before, $cached($this->store));
    }

    #[Test]
    public function a_site_that_is_not_on_flex_is_left_to_rebuild(): void
    {
        $this->buildTree();

        self::assertNull(PageAclIndex::snapshot($this->grav, null));
        self::assertFalse(PageAclIndex::refresh($this->grav, null, ['data' => [], 'language' => '', 'root' => '-'], 'g', [], []));
    }

    #[Test]
    public function rules_from_disk_ignore_the_header_a_page_object_still_carries(): void
    {
        $dir = sys_get_temp_dir() . '/grav_api_rules_from_disk_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/default.md', "---\ntitle: Saved\npermissions:\n    groups:\n        editors: '-u'\n---\n\nBody\n");

        try {
            $page = $this->createMock(PageInterface::class);
            $page->method('path')->willReturn($dir);
            $page->method('root')->willReturn(false);
            $page->method('template')->willReturn('default');
            $page->method('language')->willReturn(null);
            // The object was loaded before the save: no rules in its header.
            $page->method('header')->willReturn((object) ['title' => 'Before']);

            $acl = new PageAcl();

            self::assertSame([], $acl->rulesOf($page));
            self::assertSame(['groups' => ['editors' => '-u']], $acl->rulesFromDisk($page));
        } finally {
            unlink($dir . '/default.md');
            rmdir($dir);
        }
    }
}
