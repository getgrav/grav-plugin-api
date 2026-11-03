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
    private const CACHE_PREFIX = 'api-page-acl-index-v3-';

    /** Bumped by every page write made through the API, see invalidate() and refresh(). */
    private const GENERATION_KEY = 'api-page-acl-index-generation';

    /** Parent-walk guard, as in PageAcl. */
    private const MAX_DEPTH = 64;

    /** @var array<string, bool|null> inherited verdicts, see inherited() */
    private array $memo = [];

    /**
     * @param array<string, string|null> $parents page key => parent page key (null for the pages root)
     * @param array<string, array<string, mixed>> $rules page key => its non-empty `permissions` block
     * @param array<string, true> $listable keys of the pages a listing can return
     * @param array<string, string> $stamps page key => the storage's checksum of the page when it was indexed
     *                                       (empty when the storage has none), which is what refresh() compares
     */
    public function __construct(
        private readonly array $parents,
        private readonly array $rules,
        private readonly array $listable,
        private readonly array $stamps = [],
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
     * is. A write that goes through refresh() stores the updated index under
     * the new stamp instead of leaving the next listing to rebuild it.
     */
    public static function load(Grav $grav, ?FlexDirectory $directory, PageAcl $acl): self
    {
        $env = self::environment($grav, $directory);

        $cache = null;
        $key = null;
        if ($env['checksum'] !== null && $env['checksum'] !== '') {
            try {
                $cache = $grav['cache'];
            } catch (Throwable) {
                $cache = null;
            }
            $key = self::cacheKey($env, (string) ($cache?->fetch(self::GENERATION_KEY) ?: '0'));
            $cached = $cache?->fetch($key);
            if (is_array($cached)) {
                $index = self::fromArray($cached);
                if ($index !== null) {
                    return $index;
                }
            }
        }

        $index = self::build($env['source'], $acl, $env['listable'], self::stampsOf($env['index']));
        if ($cache !== null && $key !== null) {
            $cache->save($key, $index->toArray());
        }

        return $index;
    }

    /**
     * Everything besides the generation that the cached index is keyed by.
     *
     * @return array{checksum: string|null, language: string, root: string, flex: bool, index: object|null, source: iterable<mixed>, listable: callable(PageInterface): bool}
     */
    private static function environment(Grav $grav, ?FlexDirectory $directory): array
    {
        $language = '';
        try {
            $language = (string) ($grav['language']->getActive() ?: '');
        } catch (Throwable) {
        }

        $index = null;
        if ($directory !== null) {
            // The index, whatever the directory has loaded so far: a
            // collection works its checksum out of its objects by another
            // formula, and the stamp has to come out the same on every request.
            $index = $directory->getIndex();
            $checksum = method_exists($index, 'getCacheChecksum') ? (string) $index->getCacheChecksum() : null;
            $source = $directory->getCollection();
            $isListable = static fn (PageInterface $page): bool => !$page->root() && $page->exists();
        } else {
            $pages = $grav['pages'];
            $pages->enablePages();
            $checksum = method_exists($pages, 'getPagesCacheId') ? $pages->getPagesCacheId() : null;
            $source = $pages->instances();
            $isListable = static fn (PageInterface $page): bool => !$page->root()
                && (get_class($page) === \Grav\Common\Page\Page::class ? $page->file() !== null : $page->exists());
        }

        $rootFile = self::rootFile($grav);
        $rootStamp = $rootFile !== null && is_file($rootFile) ? (string) filemtime($rootFile) : '-';

        return [
            'checksum' => $checksum,
            'language' => $language,
            'root' => $rootStamp,
            'flex' => $directory !== null,
            'index' => $index,
            'source' => $source,
            'listable' => $isListable,
        ];
    }

    /**
     * @param array{checksum: string|null, language: string, root: string, flex: bool} $env
     */
    private static function cacheKey(array $env, string $generation): string
    {
        return self::CACHE_PREFIX . md5($env['checksum'] . '|' . $env['language'] . '|' . $env['root'] . '|' . $generation . '|' . ($env['flex'] ? 'flex' : 'pages'));
    }

    /**
     * What each page looked like to the storage when it was indexed, from the
     * Flex pages index (page key => checksum). Nothing for any other source.
     *
     * @return array<string, string>
     */
    private static function stampsOf(?object $source): array
    {
        $stamps = [];
        foreach (self::entriesOf($source) ?? [] as $key => [, $stamp]) {
            $stamps[$key] = $stamp;
        }

        return $stamps;
    }

    /**
     * The Flex pages index entries as page key => [flex key, stamp], or null
     * when $source isn't a Flex index.
     *
     * @return array<string, array{0: string, 1: string}>|null
     */
    private static function entriesOf(?object $source): ?array
    {
        if ($source === null || !method_exists($source, 'getIndexMap')) {
            return null;
        }

        $entries = [];
        foreach ($source->getIndexMap() as $flexKey => $meta) {
            $storageKey = is_array($meta) ? ($meta['storage_key'] ?? null) : null;
            if (!is_string($storageKey) || $storageKey === '') {
                return null;
            }
            $entries['/' . $storageKey] = [(string) $flexKey, (string) ($meta['checksum'] ?? $meta['storage_timestamp'] ?? '')];
        }

        return $entries;
    }

    /**
     * Forget every cached index. The pages checksums only move by whole
     * seconds, so two saves of the same page within one second (adding a rule
     * and taking it out again) would otherwise leave the first rule cached.
     * Called wherever the API itself writes pages. Returns the new stamp, for
     * refresh(), or null when it couldn't be stored.
     */
    public static function invalidate(Grav $grav): ?string
    {
        try {
            $generation = bin2hex(random_bytes(8));
            $grav['cache']->save(self::GENERATION_KEY, $generation);

            return $generation;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The cached index as it stands before an API write, for refresh(). Null
     * when there isn't one (nobody has listed pages since the last change),
     * in which case there is nothing to update and the next listing builds it.
     *
     * @return array{data: array<string, mixed>, language: string, root: string}|null
     */
    public static function snapshot(Grav $grav, ?FlexDirectory $directory): ?array
    {
        if ($directory === null) {
            return null;
        }

        try {
            $env = self::environment($grav, $directory);
            if ($env['checksum'] === null || $env['checksum'] === '') {
                return null;
            }
            $cached = $grav['cache']->fetch(self::cacheKey($env, (string) ($grav['cache']->fetch(self::GENERATION_KEY) ?: '0')));
        } catch (Throwable) {
            return null;
        }

        return is_array($cached) && self::fromArray($cached) !== null
            ? ['data' => $cached, 'language' => $env['language'], 'root' => $env['root']]
            : null;
    }

    /**
     * Carry the index from before an API write (see snapshot()) over to the
     * pages as they are now, and store it under the new checksum and the stamp
     * invalidate() returned, instead of leaving the next listing to read every
     * page's frontmatter again.
     *
     * Only pages the storage reports as new or changed since the snapshot, the
     * ones in $exact and everything at or below a folder in $subtrees are read
     * again (keys are folder paths, as keyOf() gives them); pages that are gone
     * are dropped. $exact is the page an API save wrote: its checksum can stay
     * the same when it was written twice within a second. $subtrees is each
     * folder a move, copy or reorder renamed, old path and new path, since the
     * same path can end up holding a different page.
     *
     * Does nothing, leaving the next listing to build the index, whenever it
     * can't be sure of the result: no snapshot, not a Flex site, another write
     * or a different language or `root.md` since the snapshot, a page that
     * can't be loaded, or a tree that no longer hangs together. An edit made on
     * disk still moves the checksum, so it is never read from a stale index.
     *
     * @param array{data: array<string, mixed>, language: string, root: string}|null $snapshot
     * @param list<string> $exact
     * @param list<string> $subtrees
     * @return bool whether the refreshed index was stored
     */
    public static function refresh(Grav $grav, ?FlexDirectory $directory, ?array $snapshot, ?string $generation, array $exact, array $subtrees): bool
    {
        if ($snapshot === null || $directory === null || $generation === null) {
            return false;
        }

        try {
            $env = self::environment($grav, $directory);
            $cache = $grav['cache'];
            if ($env['checksum'] === null || $env['checksum'] === ''
                || $env['language'] !== $snapshot['language']
                || $env['root'] !== $snapshot['root']
                || (string) $cache->fetch(self::GENERATION_KEY) !== $generation
            ) {
                return false;
            }

            $old = self::fromArray($snapshot['data']);
            $collection = $env['index'];
            $entries = self::entriesOf($collection);
            if ($old === null || $entries === null || !method_exists($collection, 'get')) {
                return false;
            }

            // A fresh resolver reading the files: the request's own one, and the
            // page objects, remember what they read before the pages were saved.
            $acl = new PageAcl();
            $index = $old->refreshed(
                $entries,
                static fn (string $flexKey): ?PageInterface => ($page = $collection->get($flexKey)) instanceof PageInterface ? $page : null,
                static fn (PageInterface $page): array => $acl->rulesFromDisk($page),
                $env['listable'],
                $exact,
                $subtrees,
            );
            if ($index === null) {
                return false;
            }

            $cache->save(self::cacheKey($env, $generation), $index->toArray());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * This index brought up to date with $entries (page key => [flex key,
     * stamp]), the pages as they are now. See refresh().
     *
     * @param array<string, array{0: string, 1: string}> $entries
     * @param callable(string): ?PageInterface $loadPage page by flex key
     * @param callable(PageInterface): array<string, mixed> $rulesOf the page's own `permissions` block
     * @param callable(PageInterface): bool $isListable
     * @param list<string> $exact
     * @param list<string> $subtrees
     */
    public function refreshed(array $entries, callable $loadPage, callable $rulesOf, callable $isListable, array $exact, array $subtrees): ?self
    {
        $parents = $this->parents;
        $rules = $this->rules;
        $listable = $this->listable;
        $exactSet = array_flip($exact);
        $subtrees = array_map(static fn (string $path): string => rtrim($path, '/'), $subtrees);

        // Pages that are gone. The pages root (the page with no parent, '/' on
        // Flex) isn't a storage entry.
        foreach ($this->parents as $key => $parentKey) {
            if ($parentKey !== null && !isset($entries[$key])) {
                unset($parents[$key], $rules[$key], $listable[$key]);
            }
        }

        $stamps = [];
        foreach ($entries as $key => [$flexKey, $stamp]) {
            $stamps[$key] = $stamp;

            $dirty = !array_key_exists($key, $this->parents)
                || ($this->stamps[$key] ?? null) !== $stamp
                || isset($exactSet[$key]);
            foreach ($subtrees as $path) {
                if ($dirty) {
                    break;
                }
                $dirty = $path !== '' && ($key === $path || str_starts_with($key, $path . '/'));
            }
            if (!$dirty) {
                continue;
            }

            $page = $loadPage($flexKey);
            if ($page === null || self::keyOf($page) !== $key) {
                return null;
            }

            $parent = $page->parent();
            $parentKey = $parent !== null ? self::keyOf($parent) : '';
            $parents[$key] = $parentKey !== '' && $parentKey !== $key ? $parentKey : null;

            $block = $rulesOf($page);
            if ($block !== []) {
                $rules[$key] = $block;
            } else {
                unset($rules[$key]);
            }

            if ($isListable($page)) {
                $listable[$key] = true;
            } else {
                unset($listable[$key]);
            }
        }

        // Every page hangs off a page that is indexed too, and every page the
        // storage lists is indexed: anything else means this walk missed
        // something, so leave it to a full build.
        foreach ($parents as $key => $parentKey) {
            if ($parentKey !== null && !array_key_exists($parentKey, $parents)) {
                return null;
            }
        }
        foreach ($entries as $key => $_) {
            if (!array_key_exists($key, $parents)) {
                return null;
            }
        }

        return new self($parents, $rules, $listable, $stamps);
    }

    /**
     * Index every page in $pages, and every ancestor of each one.
     *
     * @param iterable<mixed> $pages
     * @param callable(PageInterface): bool $isListable
     * @param array<string, string> $stamps see __construct()
     */
    public static function build(iterable $pages, PageAcl $acl, callable $isListable, array $stamps = []): self
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

        return new self($parents, $rules, $listable, $stamps);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (($data['v'] ?? null) !== 3
            || !is_array($data['parents'] ?? null)
            || !is_array($data['rules'] ?? null)
            || !is_array($data['listable'] ?? null)
            || !is_array($data['stamps'] ?? null)) {
            return null;
        }

        return new self($data['parents'], $data['rules'], $data['listable'], $data['stamps']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['v' => 3, 'parents' => $this->parents, 'rules' => $this->rules, 'listable' => $this->listable, 'stamps' => $this->stamps];
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
