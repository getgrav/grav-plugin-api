<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Per-page access control from a page's own frontmatter.
 *
 * Grav has always let a page carry its own rules in `header.permissions`:
 *
 *     permissions:
 *         inherit: true              # walk up to the parent when no rule matches
 *         authors: [jane]            # who counts as an "author" of this page
 *         groups:
 *             editors: 'crud'        # or { update: true, delete: false }
 *             authors: 'ud'
 *             defaults: '-d'
 *
 * Core only ever enforced this for Flex Pages, through
 * `Grav\Framework\Flex\Pages\Traits\PageAuthorsTrait`, and the API plugin never
 * consulted it at all — so on Admin-Next the whole block was inert in both
 * directions: a page-level grant didn't let an editor save, and a page-level
 * deny didn't stop one (getgrav/grav-plugin-admin2#150). This resolver applies
 * the same rules to any page, Flex or regular, reading nothing but frontmatter.
 *
 * Semantics deliberately mirror PageAuthorsTrait::isAuthorizedByGroup():
 *
 *   1. any matching group that DENIES the action wins outright — false
 *   2. otherwise any matching group that ALLOWS it — true
 *   3. no matching rule — null, meaning "this page has no opinion", and the
 *      caller falls back to the account's own `api.pages.*` permission
 *
 * A verdict of null walks up to the parent page unless the page sets
 * `permissions.inherit: false`.
 */
final class PageAcl
{
    /** Actions a page rule can speak about, in `crudl` + publish order. */
    public const ACTIONS = ['create', 'read', 'update', 'delete', 'publish', 'list'];

    /**
     * Letter shorthand accepted in a rule string, e.g. `'cru-d'`. Same map
     * PageAuthorsTrait uses, so a site's existing frontmatter reads identically.
     */
    private const RULES = [
        'c' => 'create',
        'r' => 'read',
        'u' => 'update',
        'd' => 'delete',
        'p' => 'publish',
        'l' => 'list',
    ];

    /** Parent-walk guard, in case a storage backend ever hands back a cycle. */
    private const MAX_DEPTH = 64;

    /** @var array<string, array<string, mixed>> permissions block, keyed by page */
    private array $cache = [];

    /**
     * Resolve one action against a page's own rules.
     *
     * @return bool|null true = allowed, false = denied, null = no rule applies
     */
    public function authorize(PageInterface $page, UserInterface $user, string $action): ?bool
    {
        $userGroups = $this->userGroups($user);

        // Authorship is a property of the page being acted on, not of whichever
        // ancestor happens to carry the rule — same as core, where isAuthor is
        // computed once and passed down the parent chain.
        $isAuthor = $this->isAuthor($this->permissionsOf($page), $user);

        $current = $page;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $permissions = $this->permissionsOf($current);

            $verdict = $this->verdict($permissions, $userGroups, $isAuthor, $action);
            if ($verdict !== null) {
                return $verdict;
            }

            // `inherit: false` stops the walk at this page.
            if (array_key_exists('inherit', $permissions)
                && !filter_var($permissions['inherit'], FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }

            $current = $current->parent();
            $depth++;
        }

        return null;
    }

    /**
     * Resolve every action for a page, falling back to the caller's account-wide
     * permissions wherever the page has no opinion.
     *
     * @param array<string, bool> $base account-level fallback, keyed by action
     * @return array<string, bool>
     */
    public function capabilities(PageInterface $page, UserInterface $user, array $base): array
    {
        $result = [];
        foreach (self::ACTIONS as $action) {
            $result[$action] = $this->authorize($page, $user, $action) ?? ($base[$action] ?? false);
        }

        // Publishing edits the page's frontmatter, so a page that denies editing
        // denies publishing too unless it says otherwise. Sites rarely write an
        // explicit publish rule — `crud` has no `p` — and without this fallback
        // a page marked `-u` would still advertise a working publish toggle.
        $result['publish'] = $this->authorize($page, $user, 'publish')
            ?? $this->authorize($page, $user, 'update')
            ?? ($base['publish'] ?? false);

        return $result;
    }

    /**
     * Whether any page in this page's inheritance chain carries rules at all.
     * Lets callers skip per-page work on the overwhelmingly common case of a
     * site that uses no page-level permissions.
     */
    public function hasRules(PageInterface $page): bool
    {
        $current = $page;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $permissions = $this->permissionsOf($current);
            if (!empty($permissions['groups'])) {
                return true;
            }
            if (array_key_exists('inherit', $permissions)
                && !filter_var($permissions['inherit'], FILTER_VALIDATE_BOOLEAN)) {
                return false;
            }
            $current = $current->parent();
            $depth++;
        }

        return false;
    }

    /**
     * Apply one page's `permissions.groups` block to an action.
     *
     * @param array<string, mixed> $permissions
     * @param list<string> $userGroups
     */
    private function verdict(array $permissions, array $userGroups, bool $isAuthor, string $action): ?bool
    {
        $groups = $permissions['groups'] ?? null;
        if (!is_array($groups) || $groups === []) {
            return null;
        }

        $authorized = null;

        foreach ($groups as $group => $rules) {
            $group = (string) $group;

            if ($group === 'defaults') {
                // Applies to every logged-in user. Guests never reach the API,
                // so unlike core there is no anonymous case to exclude here.
            } elseif ($group === 'authors') {
                if (!$isAuthor) {
                    continue;
                }
            } elseif (!in_array($group, $userGroups, true)) {
                continue;
            }

            $auth = $this->ruleVerdict($rules, $action);

            if ($auth === false) {
                // A deny is final — it beats a grant from any other group.
                return false;
            }
            if ($auth === true) {
                $authorized = true;
            }
        }

        return $authorized;
    }

    /**
     * One group's rule applied to one action.
     *
     * Accepts both shapes Grav writes: the letter shorthand a hand-edited
     * frontmatter uses (`'cru-d'`) and the map the admin's ACL picker saves
     * (`{update: true, delete: false}`). Anything the rule doesn't mention
     * returns null so the next group — or the parent page — gets a say.
     *
     * Mirrors {@see \Grav\Framework\Acl\Access}, which is what core's Flex pages
     * evaluate these rules with, for the flat crudl keys page rules can hold.
     */
    private function ruleVerdict(mixed $rules, string $action): ?bool
    {
        if (is_string($rules)) {
            return $this->parseShorthand($rules)[$action] ?? null;
        }

        if (!is_array($rules) || !array_key_exists($action, $rules)) {
            return null;
        }

        $value = $rules[$action];

        return match (true) {
            is_bool($value) => $value,
            $value === 0, $value === 1 => (bool) $value,
            is_string($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default => null,
        };
    }

    /**
     * Expand a `crudl` shorthand string into per-action booleans. A `-` (or `+`)
     * applies to the letter immediately after it, so `'-ud'` denies update and
     * still allows delete — same as everywhere else Grav reads these strings.
     *
     * @return array<string, bool>
     */
    private function parseShorthand(string $rules): array
    {
        $result = [];
        $allow = true;

        foreach (str_split($rules) as $letter) {
            if ($letter === '-') {
                $allow = false;
            } elseif ($letter === '+') {
                $allow = true;
            } elseif (isset(self::RULES[$letter])) {
                $result[self::RULES[$letter]] = $allow;
                $allow = true;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $permissions
     */
    private function isAuthor(array $permissions, UserInterface $user): bool
    {
        $authors = $permissions['authors'] ?? null;
        if (!is_array($authors) || $authors === []) {
            return false;
        }

        $username = (string) ($user->get('username') ?? $user->username ?? '');
        if ($username === '') {
            return false;
        }

        foreach ($authors as $author) {
            if (is_string($author) && $author === $username) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function userGroups(UserInterface $user): array
    {
        $groups = $user->get('groups');
        if (!is_array($groups)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($group) => is_string($group) ? $group : null, $groups),
        ));
    }

    /**
     * The `permissions` block from a page's frontmatter, cached per page.
     *
     * @return array<string, mixed>
     */
    private function permissionsOf(PageInterface $page): array
    {
        $key = ($page->path() ?: '') . '|' . ($page->language() ?: '') . '|' . spl_object_id($page);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $header = $page->header();
        $headerArray = $this->headerToArray($header);

        // A Flex-indexed PageObject reports an EMPTY header during listings —
        // the index only materializes summary fields — so a page's rules would
        // silently vanish on exactly the endpoint that renders its action
        // buttons. Read the frontmatter off disk in that case, the same
        // fallback PageSerializer uses for published/visible.
        if ($headerArray === []) {
            $headerArray = $this->headerFromDisk($page);
        }

        $permissions = $headerArray['permissions'] ?? null;

        return $this->cache[$key] = is_array($permissions) ? $permissions : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerToArray(mixed $header): array
    {
        if (is_array($header)) {
            return $header;
        }
        if (!is_object($header)) {
            return [];
        }

        $decoded = json_decode((string) json_encode($header), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function headerFromDisk(PageInterface $page): array
    {
        $path = $page->path();
        $template = $page->template();
        if (!$path || !$template) {
            return [];
        }

        $candidates = [];
        $language = $page->language();
        if ($language) {
            $candidates[] = $path . '/' . $template . '.' . $language . '.md';
        }
        $candidates[] = $path . '/' . $template . '.md';
        foreach (glob($path . '/' . $template . '*.md') ?: [] as $file) {
            $candidates[] = $file;
        }

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $header = $this->parseFrontmatter($file);
            if ($header !== []) {
                return $header;
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $contents, $matches)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }
}
