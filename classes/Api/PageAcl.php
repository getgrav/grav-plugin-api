<?php

declare(strict_types=1);

namespace Grav\Plugin\Api;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Services\FrontmatterReader;

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
     * Letter shorthand accepted in a rule string, e.g. `'crm-d'`. The core
     * letters match PageAuthorsTrait's map, so a site's existing frontmatter
     * reads identically; `m` adds media management (see {@see authorizeMedia()})
     * and is evaluated by the API only — core never asks for that action.
     */
    private const RULES = [
        'c' => 'create',
        'r' => 'read',
        'u' => 'update',
        'd' => 'delete',
        'p' => 'publish',
        'l' => 'list',
        'm' => 'media',
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
        return $this->resolve($page, $user, $action);
    }

    /**
     * Resolve whether the user may manage the page's media, allowing for the
     * `media` action (letter `m`) without breaking the rules a site already
     * has (getgrav/grav-plugin-api#77).
     *
     * Media is a subset of page editing for every existing rule, so each group
     * answers `media` with its own `media` key when it has one, and otherwise
     * with that group's `update` rule. That keeps the verdict local to the
     * block that wrote it: a child page's `-u` still beats a parent's
     * `media: true`, and `media: false` next to `update: true` can withhold
     * media from a group that may edit the page.
     *
     * @return bool|null true = allowed, false = denied, null = no rule applies
     */
    public function authorizeMedia(PageInterface $page, UserInterface $user): ?bool
    {
        return $this->resolve($page, $user, 'media', 'update');
    }

    /**
     * Walk a page and its ancestors applying one action, optionally falling
     * back to another action inside each group's own block.
     *
     * @return bool|null
     */
    private function resolve(
        PageInterface $page,
        UserInterface $user,
        string $action,
        ?string $fallbackAction = null,
    ): ?bool {
        // Authorship is a property of the page being acted on, not of whichever
        // ancestor happens to carry the rule — same as core, where isAuthor is
        // computed once and passed down the parent chain.
        $isAuthor = $this->isAuthorOf($this->permissionsOf($page), $user);

        $current = $page;
        $depth = 0;

        while ($current !== null && $depth < self::MAX_DEPTH) {
            $permissions = $this->permissionsOf($current);

            $verdict = $this->blockVerdict($permissions, $user, $isAuthor, $action, $fallbackAction);
            if ($verdict !== null) {
                return $verdict;
            }

            if (self::stopsInheritance($permissions)) {
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
        return self::combine(fn (string $action): ?bool => $this->authorize($page, $user, $action), $base);
    }

    /**
     * Turn per-action verdicts into a full capability map: each action takes
     * the page's verdict, or the account-wide fallback where there is none.
     *
     * Publishing edits the page's frontmatter, so a page that denies editing
     * denies publishing too unless it says otherwise. Sites rarely write an
     * explicit publish rule — `crud` has no `p` — and without this fallback a
     * page marked `-u` would still advertise a working publish toggle.
     *
     * @param callable(string): ?bool $authorize verdict for one action
     * @param array<string, bool> $base account-level fallback, keyed by action
     * @return array<string, bool>
     */
    public static function combine(callable $authorize, array $base): array
    {
        $verdicts = [];
        $result = [];
        foreach (self::ACTIONS as $action) {
            $verdicts[$action] = $authorize($action);
            $result[$action] = $verdicts[$action] ?? ($base[$action] ?? false);
        }

        $result['publish'] = $verdicts['publish'] ?? $verdicts['update'] ?? ($base['publish'] ?? false);

        return $result;
    }

    /**
     * A page's own `permissions` block, as the rules above read it. Used to
     * index every page's rules once (see PageAclIndex).
     *
     * @return array<string, mixed>
     */
    public function rulesOf(PageInterface $page): array
    {
        return $this->permissionsOf($page);
    }

    /**
     * rulesOf(), but read from the page's file on disk instead of from the
     * page object, which can still hold the header it was loaded with when the
     * page has just been saved. For bringing an index up to date after a write.
     *
     * @return array<string, mixed>
     */
    public function rulesFromDisk(PageInterface $page): array
    {
        $header = $page->root()
            ? FrontmatterReader::parse(rtrim((string) $page->path(), '/') . '/root.md')
            : FrontmatterReader::forPage($page);
        $permissions = $header['permissions'] ?? null;

        return is_array($permissions) ? $permissions : [];
    }

    /**
     * Whether a `permissions` block ends the walk up to the parent: a page that
     * sets `inherit: false` and has no verdict of its own has no opinion.
     *
     * @param array<string, mixed> $permissions
     */
    public static function stopsInheritance(array $permissions): bool
    {
        return array_key_exists('inherit', $permissions)
            && !filter_var($permissions['inherit'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Apply one page's `permissions` block to an action for this user.
     *
     * @param array<string, mixed> $permissions
     * @param bool $isAuthor whether the user is an author of the page being acted on
     */
    public function blockVerdict(
        array $permissions,
        UserInterface $user,
        bool $isAuthor,
        string $action,
        ?string $fallbackAction = null,
    ): ?bool {
        if (empty($permissions['groups'])) {
            return null;
        }

        return $this->verdict($permissions, $this->userGroups($user), $isAuthor, $action, $fallbackAction);
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
            if (self::stopsInheritance($permissions)) {
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
    private function verdict(
        array $permissions,
        array $userGroups,
        bool $isAuthor,
        string $action,
        ?string $fallbackAction = null,
    ): ?bool {
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

            // A group that says nothing about the action may still have an
            // opinion through the action it implies (media -> update).
            $auth = $this->ruleVerdict($rules, $action)
                ?? ($fallbackAction !== null ? $this->ruleVerdict($rules, $fallbackAction) : null);

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
     * applies to every letter after it until the next sign, so `'-ud'` denies both
     * update and delete and `'-c+r-ud'` is what the ACL picker writes for C, U and D
     * denied with R allowed — same as core's {@see \Grav\Framework\Acl\Access}.
     * Letters before any sign are allowed.
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
            }
        }

        return $result;
    }

    /**
     * Whether the user is listed in a page's own `permissions.authors`.
     *
     * @param array<string, mixed> $permissions
     */
    public function isAuthorOf(array $permissions, UserInterface $user): bool
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
            // The pages root has no content file of its own in a regular
            // site, so its rules live in `user/pages/root.md`, the same file a
            // Flex site keeps them in. That is where "who may create top-level
            // pages" is decided: a top-level page's parent is the root.
            $headerArray = $page->root()
                ? FrontmatterReader::parse(rtrim((string) $page->path(), '/') . '/root.md')
                : FrontmatterReader::forPage($page);
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
}
