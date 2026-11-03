<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Flex\FlexDirectory;
use Throwable;

/**
 * Every page's place in the tree and its own `permissions` block, gathered
 * once and cached until the pages change.
 *
 * Page listings need a verdict for far more pages than they return: a folder
 * listing keeps a child the caller can't read when there is a page they can
 * read somewhere below it (getgrav/grav-plugin-admin2#209). Walking each
 * page's parents through PageAcl reads frontmatter from disk for every page
 * on a Flex site, so the index holds what that walk needs instead, keyed by
 * the page's folder (see keyOf()):
 *
 *   - the parent of every page (the same chain `PageInterface::parent()` walks)
 *   - the `permissions` block of every page that has one
 *   - which pages a listing can return (not the pages root, not bare folders)
 *
 * The rules are evaluated with PageAcl's own block logic, so a verdict from
 * here is the verdict `PageAcl::authorize()` gives for the same page.
 *
 * A site with no page rules ends up with an empty rules map, which is what
 * lets a listing skip all of this (see hasRules()).
 */
final class PageAclIndex
{
    private const CACHE_PREFIX = 'api-page-acl-index-v2-';

    /** Bumped by every page write made through the API, see invalidate(). */
    private const GENERATION_KEY = 'api-page-acl-index-generation';

    /** Parent-walk guard, as in PageAcl. */
    private const MAX_DEPTH = 64;

    /** @var array<string, bool|null> inherited verdicts, see inherited() */
    private array $memo = [];

    /**
     * @param array<string, string|null> $parents page key => parent page key (null for the pages root)
     * @param array<string, array<string, mixed>> $rules page key => its non-empty `permissions` block
     * @param array<string, true> $listable keys of the pages a listing can return
     */
    public function __construct(
        private readonly array $parents,
        private readonly array $rules,
        private readonly array $listable,
    ) {
    }

    /**
     * The key a page is indexed under: its Flex storage key (the folder path
     * below `user/pages`, e.g. `/06.archives/001.archive-001`, and `/` for the
     * pages root), or its folder's path for any other page. Both name the
     * folder, so they are unique. `path()` isn't used for Flex pages because
     * a Flex page works it out through the locator on every call, which on a
     * 500-row folder took longer than the rest of the listing.
     */
    public static function keyOf(PageInterface $page): string
    {
        if ($page instanceof \Grav\Framework\Flex\Interfaces\FlexObjectInterface) {
            return '/' . $page->getStorageKey();
        }

        return (string) $page->path();
    }

    /**
     * The index for the site's pages in the active language, from the Grav
     * cache when the pages haven't changed since it was built.
     *
     * The cache key follows the same checksum the pages index itself is cached
     * by (the Flex index's, or the Pages service's cache id), plus the active
     * language, `root.md` and a stamp every API page write bumps
     * (invalidate()), so an edited rule is picked up as soon as the page list
     * is.
     */
    public static function load(Grav $grav, ?FlexDirectory $directory, PageAcl $acl): self
    {
        $language = '';
        try {
            $language = (string) ($grav['language']->getActive() ?: '');
        } catch (Throwable) {
        }

        if ($directory !== null) {
            $collection = $directory->getCollection();
            $checksum = method_exists($collection, 'getCacheChecksum') ? (string) $collection->getCacheChecksum() : null;
            $source = $collection;
            $isListable = static fn (PageInterface $page): bool => !$page->root() && $page->exists();
        } else {
            $pages = $grav['pages'];
            $pages->enablePages();
            $checksum = method_exists($pages, 'getPagesCacheId') ? $pages->getPagesCacheId() : null;
            $source = $pages->instances();
            $isListable = static fn (PageInterface $page): bool => !$page->root()
                && (get_class($page) === \Grav\Common\Page\Page::class ? $page->file() !== null : $page->exists());
        }

        $cache = null;
        $key = null;
        if ($checksum !== null && $checksum !== '') {
            $rootFile = self::rootFile($grav);
            $rootStamp = $rootFile !== null && is_file($rootFile) ? (string) filemtime($rootFile) : '-';
            try {
                $cache = $grav['cache'];
            } catch (Throwable) {
                $cache = null;
            }
            $generation = (string) ($cache?->fetch(self::GENERATION_KEY) ?: '0');
            $key = self::CACHE_PREFIX . md5($checksum . '|' . $language . '|' . $rootStamp . '|' . $generation . '|' . ($directory !== null ? 'flex' : 'pages'));
            $cached = $cache?->fetch($key);
            if (is_array($cached)) {
                $index = self::fromArray($cached);
                if ($index !== null) {
                    return $index;
                }
            }
        }

        $index = self::build($source, $acl, $isListable);
        if ($cache !== null && $key !== null) {
            $cache->save($key, $index->toArray());
        }

        return $index;
    }

    /**
     * Forget every cached index. The pages checksums only move by whole
     * seconds, so two saves of the same page within one second (adding a rule
     * and taking it out again) would otherwise leave the first rule cached.
     * Called wherever the API itself writes pages.
     */
    public static function invalidate(Grav $grav): void
    {
        try {
            $grav['cache']->save(self::GENERATION_KEY, bin2hex(random_bytes(8)));
        } catch (Throwable) {
        }
    }

    /**
     * Index every page in $pages, and every ancestor of each one.
     *
     * @param iterable<mixed> $pages
     * @param callable(PageInterface): bool $isListable
     */
    public static function build(iterable $pages, PageAcl $acl, callable $isListable): self
    {
        $parents = [];
        $rules = [];
        $listable = [];

        foreach ($pages as $page) {
            if (!$page instanceof PageInterface) {
                continue;
            }
            $path = self::keyOf($page);
            if ($path === '') {
                continue;
            }
            if ($isListable($page)) {
                $listable[$path] = true;
            }

            $current = $page;
            $depth = 0;
            while ($current !== null && $depth < self::MAX_DEPTH) {
                $currentPath = self::keyOf($current);
                if ($currentPath === '' || array_key_exists($currentPath, $parents)) {
                    break;
                }
                $parent = $current->parent();
                $parentPath = $parent !== null ? self::keyOf($parent) : '';
                $parents[$currentPath] = $parentPath !== '' && $parentPath !== $currentPath ? $parentPath : null;

                $block = $acl->rulesOf($current);
                if ($block !== []) {
                    $rules[$currentPath] = $block;
                }

                $current = $parent;
                $depth++;
            }
        }

        return new self($parents, $rules, $listable);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['v'] ?? null) !== 2
            || !is_array($data['parents'] ?? null)
            || !is_array($data['rules'] ?? null)
            || !is_array($data['listable'] ?? null)) {
            return null;
        }

        return new self($data['parents'], $data['rules'], $data['listable']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['v' => 2, 'parents' => $this->parents, 'rules' => $this->rules, 'listable' => $this->listable];
    }

    /** Whether any page on the site carries a `permissions` block. */
    public function hasRules(): bool
    {
        return $this->rules !== [];
    }

    public function has(string $path): bool
    {
        return array_key_exists($path, $this->parents);
    }

    public function parentOf(string $path): ?string
    {
        return $this->parents[$path] ?? null;
    }

    /**
     * Keys of every page a listing can return.
     *
     * @return list<string>
     */
    public function listableKeys(): array
    {
        return array_keys($this->listable);
    }

    /**
     * PageAcl::authorize() for the page with key $path, read from the index.
     *
     * @return bool|null true = allowed, false = denied, null = no rule applies
     */
    public function authorize(string $path, PageAcl $acl, UserInterface $user, string $action): ?bool
    {
        $own = $this->rules[$path] ?? [];

        // The rare page that names this user as an author: authorship is
        // judged on this page and carried up the whole chain, so it can't
        // share the walk cached for everyone else.
        if ($own !== [] && $acl->isAuthorOf($own, $user)) {
            $current = $path;
            $depth = 0;
            while ($current !== null && $depth < self::MAX_DEPTH) {
                $block = $this->rules[$current] ?? [];
                $verdict = $block !== [] ? $acl->blockVerdict($block, $user, true, $action) : null;
                if ($verdict !== null) {
                    return $verdict;
                }
                if (PageAcl::stopsInheritance($block)) {
                    return null;
                }
                $current = $this->parents[$current] ?? null;
                $depth++;
            }

            return null;
        }

        return $this->inherited($path, $acl, $user, $action, 0);
    }

    /**
     * The verdict for $path when the user is not one of its authors, cached
     * per page so siblings share their parents' walk.
     */
    private function inherited(string $path, PageAcl $acl, UserInterface $user, string $action, int $depth): ?bool
    {
        $key = spl_object_id($user) . '|' . $action . '|' . $path;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $block = $this->rules[$path] ?? [];
        $verdict = $block !== [] ? $acl->blockVerdict($block, $user, false, $action) : null;

        if ($verdict === null && !PageAcl::stopsInheritance($block) && $depth < self::MAX_DEPTH) {
            $parent = $this->parents[$path] ?? null;
            if ($parent !== null) {
                $verdict = $this->inherited($parent, $acl, $user, $action, $depth + 1);
            }
        }

        return $this->memo[$key] = $verdict;
    }

    private static function rootFile(Grav $grav): ?string
    {
        try {
            $root = $grav['locator']->findResource('page://', true);
        } catch (Throwable) {
            return null;
        }

        return is_string($root) && $root !== '' ? rtrim($root, '/') . '/root.md' : null;
    }
}
