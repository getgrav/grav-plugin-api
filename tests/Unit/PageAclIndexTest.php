<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\PageAcl;
use Grav\Plugin\Api\PageAclIndex;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The page-rules index behind filtered page listings
 * (getgrav/grav-plugin-admin2#209) must give exactly the verdict PageAcl gives
 * when it walks the page objects, or a listing would show a page its own
 * route refuses (or hide one it allows).
 */
#[CoversClass(PageAclIndex::class)]
#[CoversClass(PageAcl::class)]
class PageAclIndexTest extends TestCase
{
    use BuildsPageAclTree;

    private function index(array $tree, PageAcl $acl): PageAclIndex
    {
        return PageAclIndex::build(
            $tree,
            $acl,
            static fn (PageInterface $page): bool => !$page->root() && $page->exists(),
        );
    }

    #[Test]
    public function every_verdict_matches_walking_the_pages(): void
    {
        $tree = $this->buildTree();
        $acl = new PageAcl();
        $index = $this->index($tree, $acl);

        $users = [
            $this->treeUser('info', ['redaction-informatique']),
            $this->treeUser('rh', ['redaction-rh']),
            $this->treeUser('alice', ['redaction-informatique']),
            $this->treeUser('nobody', []),
        ];

        $checked = 0;
        foreach ($users as $user) {
            foreach ($tree as $route => $page) {
                foreach (PageAcl::ACTIONS as $action) {
                    self::assertSame(
                        $acl->authorize($page, $user, $action),
                        $index->authorize(PageAclIndex::keyOf($page), $acl, $user, $action),
                        "{$user->username} {$action} {$route}",
                    );
                    $checked++;
                }
            }
        }

        self::assertSame(4 * 21 * 6, $checked);
    }

    #[Test]
    public function the_verdicts_are_the_ones_the_rules_say(): void
    {
        $tree = $this->buildTree();
        $acl = new PageAcl();
        $index = $this->index($tree, $acl);
        $info = $this->treeUser('info', ['redaction-informatique']);
        $verdict = fn (string $route, string $action): ?bool => $index->authorize(PageAclIndex::keyOf($tree[$route]), $acl, $info, $action);

        self::assertTrue($verdict('/outils/logiciels', 'update'));
        self::assertFalse($verdict('/outils/procedures-it/sauvegardes', 'update'));
        self::assertTrue($verdict('/outils/procedures-it/sauvegardes', 'read'));
        self::assertFalse($verdict('/rh/formation', 'read'));
        self::assertTrue($verdict('/rh/formation/outils-informatiques', 'update'));
        self::assertNull($verdict('/communication/newsletter', 'read'));
        self::assertFalse($verdict('/direction/comite', 'list'));
        // inherit: false with no rule of its own stops the walk: no opinion.
        self::assertNull($verdict('/direction/public', 'read'));
    }

    #[Test]
    public function authorship_is_judged_on_the_page_itself(): void
    {
        $tree = $this->buildTree();
        $acl = new PageAcl();
        $index = $this->index($tree, $acl);
        $alice = $this->treeUser('alice', []);

        // Alice is an author of /blog, so its `authors` rule applies there…
        self::assertTrue($index->authorize(PageAclIndex::keyOf($tree['/blog']), $acl, $alice, 'read'));
        self::assertFalse($index->authorize(PageAclIndex::keyOf($tree['/blog']), $acl, $alice, 'update'));
        // …but not to /blog/post, which doesn't list her: no opinion there.
        self::assertNull($index->authorize(PageAclIndex::keyOf($tree['/blog/post']), $acl, $alice, 'read'));
        self::assertNull($index->authorize(PageAclIndex::keyOf($tree['/blog/post']), $acl, $alice, 'update'));
    }

    #[Test]
    public function it_knows_the_tree_and_what_can_be_listed(): void
    {
        $tree = $this->buildTree();
        $index = $this->index($tree, new PageAcl());

        self::assertTrue($index->hasRules());
        self::assertTrue($index->has('/p'));
        self::assertSame('/p/rh/formation', $index->parentOf('/p/rh/formation/management'));
        self::assertSame('/p', $index->parentOf('/p/rh'));
        self::assertNull($index->parentOf('/p'));
        // The pages root is in the tree but is never a listing row.
        self::assertNotContains('/p', $index->listableKeys());
        self::assertCount(20, $index->listableKeys());
    }

    #[Test]
    public function a_site_without_page_rules_has_none(): void
    {
        $index = $this->index($this->buildTree(withRules: false), new PageAcl());

        self::assertFalse($index->hasRules());
        self::assertCount(20, $index->listableKeys());
    }

    #[Test]
    public function it_survives_the_cache(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        $index = $this->index($tree, $acl);

        $restored = PageAclIndex::fromArray(unserialize(serialize($index->toArray())));

        self::assertNotNull($restored);
        self::assertSame($index->toArray(), $restored->toArray());
        self::assertNull(PageAclIndex::fromArray(['v' => 0]));
        self::assertNull(PageAclIndex::fromArray([]));
    }

    #[Test]
    public function combine_falls_back_to_the_account_and_publish_follows_update(): void
    {
        $base = ['create' => false, 'read' => true, 'update' => true, 'delete' => true, 'publish' => true, 'list' => true];

        $noOpinion = PageAcl::combine(static fn (): ?bool => null, $base);
        self::assertSame($base, $noOpinion);

        $updateDenied = PageAcl::combine(static fn (string $action): ?bool => $action === 'update' ? false : null, $base);
        self::assertFalse($updateDenied['update']);
        self::assertFalse($updateDenied['publish']);
        self::assertTrue($updateDenied['delete']);
    }

    /**
     * The storage's view of the tree: page key => [flex key, stamp].
     *
     * @param array<string, PageInterface> $tree
     * @return array<string, array{0: string, 1: string}>
     */
    private function entries(array $tree, string $stamp = 's1'): array
    {
        $entries = [];
        foreach ($tree as $route => $page) {
            if (!$page->root()) {
                $entries[PageAclIndex::keyOf($page)] = [$route, $stamp];
            }
        }

        return $entries;
    }

    /**
     * An index built from $tree with the stamps entries() gives, and the
     * pieces refreshed() takes.
     *
     * @param array<string, PageInterface> $tree
     * @return array{PageAclIndex, callable, callable, callable}
     */
    private function refreshParts(array $tree, PageAcl $acl): array
    {
        $index = PageAclIndex::build(
            $tree,
            $acl,
            static fn (PageInterface $page): bool => !$page->root() && $page->exists(),
            array_map(static fn (array $entry): string => $entry[1], $this->entries($tree)),
        );

        return [
            $index,
            fn (string $route): ?PageInterface => $this->tree[$route] ?? null,
            static fn (PageInterface $page): array => $acl->rulesOf($page),
            static fn (PageInterface $page): bool => !$page->root() && $page->exists(),
        ];
    }

    #[Test]
    public function refreshing_a_tree_that_has_not_changed_reads_no_page(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, , $rulesOf, $isListable] = $this->refreshParts($tree, $acl);

        $refreshed = $index->refreshed(
            $this->entries($tree),
            static fn (string $route): never => self::fail("Read $route"),
            $rulesOf,
            $isListable,
            [],
            [],
        );

        self::assertNotNull($refreshed);
        self::assertEquals($index->toArray(), $refreshed->toArray());
    }

    #[Test]
    public function refreshing_reads_the_pages_whose_stamp_changed_and_those_it_is_told_about(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, $loadPage, , $isListable] = $this->refreshParts($tree, $acl);

        // /rh/annuaire was saved (new stamp); /outils/logiciels was saved too
        // but within the same second, so only the caller knows.
        $entries = $this->entries($tree);
        $entries['/p/rh/annuaire'][1] = 's2';
        $read = [];
        $rules = [
            '/rh/annuaire' => ['groups' => ['redaction-rh' => 'crupl']],
            '/outils/logiciels' => ['groups' => ['redaction-informatique' => '-rl']],
        ];

        $refreshed = $index->refreshed(
            $entries,
            function (string $route) use ($loadPage, &$read): ?PageInterface {
                $read[] = $route;

                return $loadPage($route);
            },
            static fn (PageInterface $page): array => $rules[$page->rawRoute()] ?? [],
            $isListable,
            ['/p/outils/logiciels'],
            [],
        );

        self::assertNotNull($refreshed);
        self::assertEqualsCanonicalizing(['/rh/annuaire', '/outils/logiciels'], $read);

        $data = $refreshed->toArray();
        self::assertSame($rules['/rh/annuaire'], $data['rules']['/p/rh/annuaire']);
        self::assertSame($rules['/outils/logiciels'], $data['rules']['/p/outils/logiciels']);
        // Everything else keeps what it had, including pages whose rules the
        // stand-in reader would have returned as none.
        self::assertSame($index->toArray()['rules']['/p/outils'], $data['rules']['/p/outils']);
        self::assertSame('s2', $data['stamps']['/p/rh/annuaire']);

        // A page whose rules were taken out loses its entry.
        $none = $index->refreshed($entries, $loadPage, static fn (): array => [], $isListable, [], []);
        self::assertArrayNotHasKey('/p/rh/annuaire', $none->toArray()['rules']);
        self::assertArrayHasKey('/p/rh', $none->toArray()['rules']);
    }

    #[Test]
    public function refreshing_drops_deleted_pages_with_everything_below_them(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, $loadPage, $rulesOf, $isListable] = $this->refreshParts($tree, $acl);

        $entries = $this->entries($tree);
        foreach (array_keys($entries) as $key) {
            if ($key === '/p/rh' || str_starts_with($key, '/p/rh/')) {
                unset($entries[$key]);
            }
        }

        $refreshed = $index->refreshed($entries, $loadPage, $rulesOf, $isListable, [], ['/p/rh']);

        self::assertNotNull($refreshed);
        $data = $refreshed->toArray();
        foreach (['parents', 'rules', 'listable', 'stamps'] as $part) {
            foreach (array_keys($data[$part]) as $key) {
                self::assertStringStartsNotWith('/p/rh', $key, "$part still has $key");
            }
        }
        self::assertArrayHasKey('/p/outils', $data['rules']);
        self::assertCount(20 - 6, $refreshed->listableKeys());
    }

    #[Test]
    public function refreshing_picks_up_new_pages_and_a_subtree_that_moved(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, , , $isListable] = $this->refreshParts($tree, $acl);

        // /comite and /public now live under /outils: the old keys are gone, the new ones added.
        $old = $this->entries($tree);
        $entries = $old;
        unset($entries['/p/direction/comite'], $entries['/p/direction/public']);
        $this->treePage('/outils/comite', ['title' => 'comite']);
        $this->treePage('/outils/public', ['title' => 'public', 'permissions' => ['inherit' => false]]);
        foreach (['/outils/comite', '/outils/public'] as $route) {
            $entries[PageAclIndex::keyOf($this->tree[$route])] = [$route, 's1'];
        }

        $refreshed = $index->refreshed(
            $entries,
            fn (string $route): ?PageInterface => $this->tree[$route] ?? null,
            static fn (PageInterface $page): array => $acl->rulesOf($page),
            $isListable,
            [],
            ['/p/direction/comite', '/p/direction/public', '/p/outils/comite', '/p/outils/public'],
        );

        self::assertNotNull($refreshed);
        self::assertFalse($refreshed->has('/p/direction/comite'));
        self::assertSame('/p/outils', $refreshed->parentOf('/p/outils/comite'));
        self::assertSame('/p/outils', $refreshed->parentOf('/p/outils/public'));
        self::assertSame(['inherit' => false], $refreshed->toArray()['rules']['/p/outils/public']);
        self::assertContains('/p/outils/comite', $refreshed->listableKeys());
        self::assertNotContains('/p/direction/comite', $refreshed->listableKeys());
    }

    #[Test]
    public function a_path_that_now_holds_another_page_is_read_again_when_its_folder_is_named(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, $loadPage, , $isListable] = $this->refreshParts($tree, $acl);

        // /rh/annuaire's folder was swapped for another page's with the same name and the same stamp.
        $read = [];
        $refreshed = $index->refreshed(
            $this->entries($tree),
            function (string $route) use ($loadPage, &$read): ?PageInterface {
                $read[] = $route;

                return $loadPage($route);
            },
            static fn (PageInterface $page): array => [],
            $isListable,
            [],
            ['/p/rh'],
        );

        self::assertNotNull($refreshed);
        self::assertContains('/rh/annuaire', $read);
        self::assertContains('/rh', $read);
        self::assertNotContains('/outils', $read);
        self::assertArrayNotHasKey('/p/rh', $refreshed->toArray()['rules']);
        self::assertArrayHasKey('/p/outils', $refreshed->toArray()['rules']);
    }

    #[Test]
    public function refreshing_gives_up_on_a_tree_that_does_not_hang_together(): void
    {
        $acl = new PageAcl();
        $tree = $this->buildTree();
        [$index, $loadPage, $rulesOf, $isListable] = $this->refreshParts($tree, $acl);

        // A page whose parent the storage doesn't list.
        $entries = $this->entries($tree);
        unset($entries['/p/rh/formation']);
        self::assertNull($index->refreshed($entries, $loadPage, $rulesOf, $isListable, [], []));

        // A page the storage lists but that can't be loaded.
        $entries = $this->entries($tree);
        $entries['/p/outils/ghost'] = ['/outils/ghost', 's1'];
        self::assertNull($index->refreshed($entries, $loadPage, $rulesOf, $isListable, [], []));

        // A page loaded under another key than the storage gave (a stale object).
        $entries = $this->entries($tree);
        $entries['/p/outils/logiciels'] = ['/outils/procedures-it', 's2'];
        self::assertNull($index->refreshed($entries, $loadPage, $rulesOf, $isListable, [], []));
    }
}
