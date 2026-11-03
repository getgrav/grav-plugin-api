<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Closure;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;

/**
 * Which pages one caller's page listings may return, and with what
 * capabilities (getgrav/grav-plugin-admin2#209).
 *
 *   - A page is visible when the caller may both `read` and `list` it.
 *   - With `editable=true` a page must also allow `update`.
 *   - A flat listing returns only the pages that pass.
 *   - A folder listing also keeps a child that doesn't pass when some page
 *     below it does, flagged `ancestor_only`, so the tree can still be opened
 *     down to that page.
 *
 * Capabilities come from PageAclIndex, so a 500-row listing evaluates the
 * rules from memory instead of reading each page's frontmatter, and the
 * set of pages that pass is worked out once per request; a folder's hidden
 * children are then checked against it by key rather than by walking their
 * descendants. A page the index doesn't know (it was created after the index
 * was cached in this same request) falls back to the controller's own
 * per-page check.
 *
 * Super admins and sites without page rules take a short cut: every page
 * gets the same capabilities, so nothing is evaluated per page at all.
 */
final class PageListVisibility
{
    /** @var array<string, array<string, bool>> page key => capabilities */
    private array $capabilities = [];

    /** @var array<string, true>|null keys of pages with a passing page below them */
    private ?array $ancestors = null;

    /** @var array<string, bool>|null capabilities shared by every page, when there is such a thing */
    private ?array $constant;

    /**
     * @param array<string, bool>|null $fixed capabilities of every page regardless of rules (super admin), or null
     * @param array<string, bool> $base account-wide fallback where a page has no rule
     * @param list<string> $deny actions the credential can never perform
     * @param Closure(PageInterface): array<string, bool> $fallback per-page capabilities for a page the index lacks
     */
    public function __construct(
        private readonly ?PageAclIndex $index,
        private readonly PageAcl $acl,
        private readonly UserInterface $user,
        ?array $fixed,
        private readonly array $base,
        private readonly array $deny,
        private readonly bool $editableOnly,
        private readonly Closure $fallback,
    ) {
        if ($fixed !== null) {
            $this->constant = $fixed;
        } elseif ($index === null || !$index->hasRules()) {
            // No page anywhere has an opinion, so every page gets the account's own.
            $this->constant = $this->mask(PageAcl::combine(static fn (): ?bool => null, $base));
        } else {
            $this->constant = null;
        }
    }

    public function editableOnly(): bool
    {
        return $this->editableOnly;
    }

    /**
     * Whether the listing has to look at pages one by one. False for super
     * admins and sites without page rules, unless `editable=true` asks for
     * something the shared capabilities don't give.
     */
    public function filters(): bool
    {
        return $this->constant === null || !$this->passes($this->constant);
    }

    /**
     * What this caller may do to the page: the listing row's `permissions`.
     *
     * @return array<string, bool>
     */
    public function capabilities(PageInterface $page): array
    {
        if ($this->constant !== null) {
            return $this->constant;
        }

        $key = PageAclIndex::keyOf($page);
        if ($key !== '' && $this->index !== null && $this->index->has($key)) {
            return $this->capabilitiesAt($key);
        }

        return ($this->fallback)($page);
    }

    /** Whether the caller may read and list the page. */
    public function isVisible(PageInterface $page): bool
    {
        $capabilities = $this->capabilities($page);

        return ($capabilities['read'] ?? false) && ($capabilities['list'] ?? false);
    }

    /** Whether the page passes: visible, and updatable under `editable=true`. */
    public function matches(PageInterface $page): bool
    {
        return $this->passes($this->capabilities($page));
    }

    /**
     * A flat listing: only the pages that pass.
     *
     * @param list<PageInterface> $pages
     * @return list<PageInterface>
     */
    public function filterFlat(array $pages): array
    {
        if (!$this->filters()) {
            return $pages;
        }

        return array_values(array_filter($pages, fn (PageInterface $page): bool => $this->matches($page)));
    }

    /**
     * A folder listing: the children that pass, plus each child that doesn't
     * but has a page below it that does. Returns the kept pages in their
     * original order and the keys (PageAclIndex::keyOf()) of the ones kept
     * only as a way down.
     *
     * @param list<PageInterface> $pages
     * @return array{0: list<PageInterface>, 1: array<string, true>}
     */
    public function filterFolder(array $pages): array
    {
        if (!$this->filters()) {
            return [$pages, []];
        }

        $kept = [];
        $ancestorOnly = [];
        foreach ($pages as $page) {
            if ($this->matches($page)) {
                $kept[] = $page;
                continue;
            }
            $key = PageAclIndex::keyOf($page);
            if ($key !== '' && isset($this->ancestors()[$key])) {
                $kept[] = $page;
                $ancestorOnly[$key] = true;
            }
        }

        return [$kept, $ancestorOnly];
    }

    /**
     * Whether some page below this one passes, so a folder listing would keep
     * it as an `ancestor_only` row.
     */
    public function leadsToMatch(PageInterface $page): bool
    {
        if (!$this->filters()) {
            return false;
        }
        $key = PageAclIndex::keyOf($page);

        return $key !== '' && isset($this->ancestors()[$key]);
    }

    /**
     * @param array<string, bool> $capabilities
     */
    private function passes(array $capabilities): bool
    {
        if (!($capabilities['read'] ?? false) || !($capabilities['list'] ?? false)) {
            return false;
        }

        return !$this->editableOnly || ($capabilities['update'] ?? false);
    }

    /**
     * @return array<string, bool>
     */
    private function capabilitiesAt(string $key): array
    {
        if (!isset($this->capabilities[$key])) {
            $index = $this->index;
            assert($index !== null);
            $this->capabilities[$key] = $this->mask(PageAcl::combine(
                fn (string $action): ?bool => $index->authorize($key, $this->acl, $this->user, $action),
                $this->base,
            ));
        }

        return $this->capabilities[$key];
    }

    /**
     * passes() for an indexed page, asking only for the actions it needs and
     * stopping at the first one denied. Same answer as passes() on
     * capabilitiesAt(), at a fraction of the work for the ancestor pass,
     * which looks at every page on the site.
     */
    private function passesAt(string $key): bool
    {
        if (isset($this->capabilities[$key])) {
            return $this->passes($this->capabilities[$key]);
        }

        $index = $this->index;
        assert($index !== null);
        foreach ($this->editableOnly ? ['read', 'list', 'update'] : ['read', 'list'] as $action) {
            if (in_array($action, $this->deny, true)) {
                return false;
            }
            if (!($index->authorize($key, $this->acl, $this->user, $action) ?? ($this->base[$action] ?? false))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every page that has a passing page somewhere below it, from one pass
     * over the index: each passing page marks its ancestors, stopping at the
     * first one already marked.
     *
     * @return array<string, true>
     */
    private function ancestors(): array
    {
        if ($this->ancestors !== null) {
            return $this->ancestors;
        }

        $ancestors = [];
        if ($this->index !== null) {
            foreach ($this->index->listableKeys() as $key) {
                if (!$this->passesAt($key)) {
                    continue;
                }
                $parent = $this->index->parentOf($key);
                while ($parent !== null && !isset($ancestors[$parent])) {
                    $ancestors[$parent] = true;
                    $parent = $this->index->parentOf($parent);
                }
            }
        }

        return $this->ancestors = $ancestors;
    }

    /**
     * @param array<string, bool> $capabilities
     * @return array<string, bool>
     */
    private function mask(array $capabilities): array
    {
        foreach ($this->deny as $action) {
            $capabilities[$action] = false;
        }

        return $capabilities;
    }
}
