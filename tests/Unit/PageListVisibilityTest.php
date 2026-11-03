<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\PageAcl;
use Grav\Plugin\Api\PageAclIndex;
use Grav\Plugin\Api\PageListVisibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which pages a page listing returns to one caller (getgrav/grav-plugin-admin2#209):
 * pages they may read and list, folder rows kept only as a way down to such a
 * page (`ancestor_only`), and the `editable=true` filter.
 */
#[CoversClass(PageListVisibility::class)]
class PageListVisibilityTest extends TestCase
{
    use BuildsPageAclTree;

    /** An editor with `api.pages.read` site-wide and no `api.pages.write`. */
    private const READER = ['create' => false, 'read' => true, 'update' => false, 'delete' => false, 'publish' => false, 'list' => true];

    private int $fallbackCalls = 0;

    /**
     * @param array<string, PageInterface> $tree
     * @param array<string, bool>|null $fixed
     * @param list<string> $deny
     */
    private function visibility(
        array $tree,
        UserInterface $user,
        bool $editableOnly = false,
        ?array $fixed = null,
        array $base = self::READER,
        array $deny = [],
        bool $index = true,
    ): PageListVisibility {
        $acl = new PageAcl();
        $built = $index
            ? PageAclIndex::build($tree, $acl, static fn (PageInterface $page): bool => !$page->root() && $page->exists())
            : null;

        return new PageListVisibility(
            $built,
            $acl,
            $user,
            $fixed,
            $base,
            $deny,
            $editableOnly,
            function (PageInterface $page) use ($acl, $user, $base, $deny): array {
                $this->fallbackCalls++;
                $capabilities = $acl->capabilities($page, $user, $base);
                foreach ($deny as $action) {
                    $capabilities[$action] = false;
                }

                return $capabilities;
            },
        );
    }

    /**
     * @param array<string, PageInterface> $tree
     * @return list<PageInterface>
     */
    private function childrenOf(array $tree, string $route): array
    {
        $prefix = $route === '/' ? '/' : $route . '/';

        return array_values(array_filter(
            $tree,
            static fn (PageInterface $page, string $candidate): bool => $candidate !== '/'
                && str_starts_with($candidate, $prefix)
                && !str_contains(substr($candidate, strlen($prefix)), '/'),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param array<string, PageInterface> $tree
     * @return list<PageInterface>
     */
    private function allPages(array $tree): array
    {
        return array_values(array_filter($tree, static fn (PageInterface $page): bool => !$page->root()));
    }

    /**
     * @param array{0: list<PageInterface>, 1: array<string, true>} $result
     * @return array<string, bool> route => ancestor_only
     */
    private static function folderRows(array $result): array
    {
        [$pages, $ancestorOnly] = $result;
        $rows = [];
        foreach ($pages as $page) {
            $rows[(string) $page->rawRoute()] = isset($ancestorOnly[PageAclIndex::keyOf($page)]);
        }

        return $rows;
    }

    #[Test]
    public function a_flat_listing_leaves_out_every_page_the_caller_may_not_read_or_list(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']));

        self::assertSame([
            '/home', '/outils', '/outils/logiciels', '/outils/procedures-it', '/outils/procedures-it/sauvegardes',
            '/rh/formation/outils-informatiques', '/rh/annuaire',
            '/communication', '/communication/newsletter', '/communication/newsletter/edition-it',
            '/direction/public', '/blog', '/blog/post',
        ], self::routesOf($visibility->filterFlat($this->allPages($tree))));
    }

    #[Test]
    public function a_folder_keeps_hidden_children_that_lead_to_a_visible_page(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']));

        // /rh and /direction can't be read, but each has a page below it that
        // can (/rh/annuaire, /direction/public), so they stay as a way down.
        self::assertSame(
            ['/home' => false, '/outils' => false, '/rh' => true, '/communication' => false, '/direction' => true, '/blog' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/'))),
        );
        self::assertSame(
            ['/rh/formation' => true, '/rh/annuaire' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/rh'))),
        );
        self::assertSame(
            ['/rh/formation/outils-informatiques' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/rh/formation'))),
        );
        // A hidden child with nothing visible below it is simply left out.
        self::assertSame(
            ['/communication/newsletter' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/communication'))),
        );
        self::assertSame(
            ['/direction/public' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/direction'))),
        );
    }

    #[Test]
    public function an_ancestor_only_row_carries_its_real_capabilities(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']));

        self::assertFalse($visibility->capabilities($tree['/rh'])['read']);
        self::assertFalse($visibility->matches($tree['/rh']));
        self::assertFalse($visibility->isVisible($tree['/rh']));
        self::assertTrue($visibility->leadsToMatch($tree['/rh']));
        self::assertFalse($visibility->leadsToMatch($tree['/communication/charte']));
    }

    #[Test]
    public function editable_keeps_only_pages_the_caller_may_update(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']), editableOnly: true);

        self::assertSame(
            ['/outils', '/outils/logiciels', '/rh/formation/outils-informatiques', '/communication/newsletter/edition-it'],
            self::routesOf($visibility->filterFlat($this->allPages($tree))),
        );
    }

    #[Test]
    public function editable_folders_keep_the_way_down_to_an_editable_page(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']), editableOnly: true);

        self::assertSame(
            ['/outils' => false, '/rh' => true, '/communication' => true],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/'))),
        );
        // /outils/procedures-it is read-only and so is everything below it.
        self::assertSame(
            ['/outils/logiciels' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/outils'))),
        );
        self::assertSame(
            ['/rh/formation' => true],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/rh'))),
        );
    }

    #[Test]
    public function a_readable_page_above_an_editable_one_is_ancestor_only_under_editable(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']), editableOnly: true);

        // /communication and /communication/newsletter can be read but not
        // edited; /communication/newsletter/edition-it can be edited.
        self::assertSame(
            ['/communication/newsletter' => true],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/communication'))),
        );
        self::assertSame(
            ['/communication/newsletter/edition-it' => false],
            self::folderRows($visibility->filterFolder($this->childrenOf($tree, '/communication/newsletter'))),
        );
        // The row is a way down, but still carries what the caller may do there.
        self::assertTrue($visibility->capabilities($tree['/communication/newsletter'])['read']);
        self::assertFalse($visibility->capabilities($tree['/communication/newsletter'])['update']);
        self::assertTrue($visibility->isVisible($tree['/communication/newsletter']));
        self::assertFalse($visibility->matches($tree['/communication/newsletter']));

        // RH may update all of /rh and nothing else.
        $rh = $this->visibility($tree, $this->treeUser('rh', ['redaction-rh']), editableOnly: true);
        self::assertSame(['/rh' => false], self::folderRows($rh->filterFolder($this->childrenOf($tree, '/'))));
    }

    #[Test]
    public function capabilities_from_the_index_match_the_per_page_check(): void
    {
        $tree = $this->buildTree();
        $acl = new PageAcl();

        foreach ([['info', ['redaction-informatique']], ['rh', ['redaction-rh']], ['alice', []]] as [$name, $groups]) {
            $user = $this->treeUser($name, $groups);
            $visibility = $this->visibility($tree, $user);
            foreach ($this->allPages($tree) as $page) {
                self::assertSame(
                    $acl->capabilities($page, $user, self::READER),
                    $visibility->capabilities($page),
                    "{$name} {$page->rawRoute()}",
                );
            }
        }

        self::assertSame(0, $this->fallbackCalls);
    }

    #[Test]
    public function super_admins_see_everything_without_any_per_page_work(): void
    {
        $tree = $this->buildTree();
        $all = array_fill_keys(PageAcl::ACTIONS, true);
        $visibility = $this->visibility($tree, $this->treeUser('admin', []), fixed: $all, index: false);

        self::assertFalse($visibility->filters());
        self::assertCount(20, $visibility->filterFlat($this->allPages($tree)));
        self::assertSame([$this->childrenOf($tree, '/'), []], $visibility->filterFolder($this->childrenOf($tree, '/')));
        self::assertSame($all, $visibility->capabilities($tree['/direction/comite']));
        self::assertSame(0, $this->fallbackCalls);
    }

    #[Test]
    public function a_site_without_page_rules_takes_the_short_cut(): void
    {
        $tree = $this->buildTree(withRules: false);
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']));

        self::assertFalse($visibility->filters());
        self::assertSame(self::READER, $visibility->capabilities($tree['/rh']));
        self::assertCount(20, $visibility->filterFlat($this->allPages($tree)));
        self::assertSame(0, $this->fallbackCalls);
    }

    #[Test]
    public function editable_on_a_site_without_rules_follows_the_account(): void
    {
        $tree = $this->buildTree(withRules: false);

        $reader = $this->visibility($tree, $this->treeUser('info', []), editableOnly: true);
        self::assertTrue($reader->filters());
        self::assertSame([], $reader->filterFlat($this->allPages($tree)));
        self::assertSame([[], []], $reader->filterFolder($this->childrenOf($tree, '/')));

        $writer = $this->visibility($tree, $this->treeUser('info', []), editableOnly: true, base: array_fill_keys(PageAcl::ACTIONS, true));
        self::assertFalse($writer->filters());
        self::assertCount(20, $writer->filterFlat($this->allPages($tree)));
    }

    #[Test]
    public function the_credential_cap_applies_to_every_page(): void
    {
        $tree = $this->buildTree();
        // An API key that may not write pages: no page grant can lift that.
        $visibility = $this->visibility(
            $tree,
            $this->treeUser('info', ['redaction-informatique']),
            editableOnly: true,
            deny: ['create', 'update', 'delete', 'publish'],
        );

        self::assertFalse($visibility->capabilities($tree['/outils'])['update']);
        self::assertSame([], $visibility->filterFlat($this->allPages($tree)));
        self::assertSame([[], []], $visibility->filterFolder($this->childrenOf($tree, '/')));
    }

    #[Test]
    public function a_page_the_index_does_not_know_falls_back_to_the_per_page_check(): void
    {
        $tree = $this->buildTree();
        $visibility = $this->visibility($tree, $this->treeUser('info', ['redaction-informatique']));

        $new = $this->createMock(PageInterface::class);
        $new->method('path')->willReturn('/p/outils/brand-new');
        $new->method('header')->willReturn((object) ['title' => 'New']);
        $new->method('parent')->willReturn($tree['/outils']);
        $new->method('template')->willReturn('default');

        self::assertTrue($visibility->capabilities($new)['update']);
        self::assertSame(1, $this->fallbackCalls);
    }
}
