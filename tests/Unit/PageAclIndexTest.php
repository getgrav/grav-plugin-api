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
}
