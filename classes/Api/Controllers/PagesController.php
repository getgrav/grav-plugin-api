<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\PageOrdering;
use Grav\Common\Security;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\TwigContentForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\FlexBackend;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RocketTheme\Toolbox\File\MarkdownFile;

class PagesController extends AbstractApiController
{
    use FlexBackend;
    private const PERMISSION_READ = 'api.pages.read';
    private const PERMISSION_WRITE = 'api.pages.write';

    private const ALLOWED_FILTERS = ['published', 'template', 'routable', 'visible', 'parent', 'children_of', 'root'];
    private const ALLOWED_SORT_FIELDS = ['date', 'title', 'slug', 'modified', 'order', 'default'];

    private readonly PageSerializer $serializer;

    public function __construct(Grav $grav, Config $config)
    {
        parent::__construct($grav, $config);
        $cacheDir = $grav['locator']->findResource('cache://') . '/api/thumbnails';
        $thumbnailService = new ThumbnailService($cacheDir);
        $baseUrl = '/' . trim($config->get('plugins.api.route', '/api'), '/') . '/' . $config->get('plugins.api.version_prefix', 'v1');
        $mediaSerializer = new MediaSerializer($thumbnailService, $baseUrl);
        $this->serializer = new PageSerializer($mediaSerializer);
    }

    /**
     * GET /pages - List pages with filtering, sorting, and pagination.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $previousLang = $this->applyLanguage($request);

        try {
            $directory = $this->getFlexDirectory('pages');
            if ($directory) {
                return $this->indexViaFlex($request, $directory);
            }
            return $this->indexViaPages($request);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * List pages using the Flex-Objects backend (indexed, cached).
     */
    private function indexViaFlex(ServerRequestInterface $request, FlexDirectory $directory): ResponseInterface
    {
        $filters = $this->getFilters($request, self::ALLOWED_FILTERS);
        $sorting = $this->getSorting($request, self::ALLOWED_SORT_FIELDS);
        $pagination = $this->getPagination($request);
        $query = $request->getQueryParams();
        $search = $query['search'] ?? null;

        $sortField = $sorting['sort'] ?? 'date';
        $sortOrder = $sorting['sort'] ? $sorting['order'] : 'desc';

        // 'default' sort with children_of: use native page ordering
        if ($sortField === 'default' && isset($filters['children_of'])) {
            return $this->indexViaDefaultSort($request, $filters['children_of'], $filters, $pagination);
        }
        if ($sortField === 'default') {
            $sortField = 'order';
            $sortOrder = 'asc';
        }

        // Start with full collection
        $collection = $directory->getCollection();

        // Apply search
        if ($search && $search !== '') {
            $collection = $collection->search($search);
        }

        // Apply every filter (published, visible, routable, template, parent,
        // children_of, root) by testing each page against matchesFilters().
        //
        // We deliberately do NOT use the flex withPublished()/withVisible()/
        // withRoutable() shortcuts: $directory->getCollection() hands back a
        // PageIndex, and those methods live only on PageCollection. The old
        // method_exists() guards therefore never fired for a PageIndex and
        // silently dropped the published/visible/routable filters, returning
        // every page unfiltered (getgrav/grav-plugin-admin2#121). matchesFilters()
        // covers all filter keys and works on any collection/index type.
        if ($filters) {
            $filtered = [];
            foreach ($collection as $page) {
                if ($page instanceof PageInterface && $this->matchesFilters($page, $filters)) {
                    $filtered[$page->getKey()] = $page;
                }
            }
            // Re-select from the collection to maintain the flex type
            $collection = $collection->select(array_keys($filtered));
        }

        // Map sort fields to flex-compatible field names
        $flexSortField = match ($sortField) {
            'date' => 'date',
            'modified' => 'timestamp',
            'title' => 'title',
            'slug' => 'slug',
            'order' => 'order',
            default => 'date',
        };
        $collection = $collection->sort([$flexSortField => $sortOrder]);

        // Skip the virtual pages-root container (no file on disk). The home
        // page IS a real file-backed page even though its route is '/'.
        $items = [];
        foreach ($collection as $page) {
            if ($page instanceof PageInterface && $page->route() && $page->exists()) {
                $items[] = $page;
            }
        }

        $total = count($items);
        $locatedAt = $this->applyLocate($items, $pagination, $query['locate'] ?? null);
        $slice = array_slice($items, $pagination['offset'], $pagination['limit']);

        $includeTranslations = filter_var(
            $request->getQueryParams()['translations'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $listOptions = [
            'include_content' => false,
            'render_content' => false,
            'include_children' => false,
            'include_media' => false,
            'include_translations' => $includeTranslations,
        ];

        $data = $this->attachPageCapabilities($request, $slice, $this->serializer->serializeCollection($slice, $listOptions));

        return ApiResponse::paginated(
            data: $data,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/pages',
            locatedAtIndex: $locatedAt,
        );
    }

    /**
     * List pages using the regular Grav Pages service (fallback).
     */
    private function indexViaPages(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $filters = $this->getFilters($request, self::ALLOWED_FILTERS);
        $sorting = $this->getSorting($request, self::ALLOWED_SORT_FIELDS);
        $pagination = $this->getPagination($request);

        $sortField = $sorting['sort'] ?? 'date';
        $sortOrder = $sorting['sort'] ? $sorting['order'] : 'desc';

        if ($sortField === 'default' && isset($filters['children_of'])) {
            return $this->indexViaDefaultSort($request, $filters['children_of'], $filters, $pagination);
        }
        if ($sortField === 'default') {
            $sortField = 'order';
            $sortOrder = 'asc';
        }

        $pages = $this->grav['pages'];
        $allPages = $this->collectAndFilterPages($pages->instances(), $filters);
        $allPages = $this->sortPages($allPages, $sortField, $sortOrder);

        $total = count($allPages);
        $locatedAt = $this->applyLocate($allPages, $pagination, $request->getQueryParams()['locate'] ?? null);
        $slice = array_slice($allPages, $pagination['offset'], $pagination['limit']);

        $includeTranslations = filter_var(
            $request->getQueryParams()['translations'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $listOptions = [
            'include_content' => false,
            'render_content' => false,
            'include_children' => false,
            'include_media' => false,
            'include_translations' => $includeTranslations,
        ];

        $data = $this->attachPageCapabilities($request, $slice, $this->serializer->serializeCollection($slice, $listOptions));

        return ApiResponse::paginated(
            data: $data,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/pages',
            locatedAtIndex: $locatedAt,
        );
    }

    /**
     * GET /pages/{route} - Get a single page by route.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_READ);
            $this->authorizePageAction($request, $page, 'read', self::PERMISSION_READ);

            // If the page already has process.twig:true, the same gate that
            // governs writes also governs reading the full record. Returning
            // the editor view to a user who can't save it is misleading; let
            // Admin Next show the toast on the show() failure instead.
            $this->guardTwigContent($request, $page, []);

            $query = $request->getQueryParams();
            $summary = filter_var($query['summary'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $options = [
                'include_content' => !$summary,
                'render_content' => filter_var($query['render'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'include_summary' => $summary,
                'summary_size' => isset($query['summary_size']) ? (int) $query['summary_size'] : null,
                'include_children' => filter_var($query['children'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'children_depth' => max(1, (int) ($query['children_depth'] ?? 1)),
                'include_media' => true,
                'include_translations' => filter_var($query['translations'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];

            $data = $this->serializer->serialize($page, $options);

            // The ETag is the page's own state — take it BEFORE attaching the
            // caller's capabilities, which vary per user and would otherwise
            // make every If-Match on a later PATCH mismatch.
            $etag = $this->generateEtag($data);
            $data['permissions'] = $this->pageCapabilities($request, $page);

            return $this->respondWithEtag($data, etag: $etag);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * POST /pages/{route}/preview-token — mint a short-lived token that lets the
     * front-end render this page even when it is unpublished, for the admin page
     * preview (getgrav/grav-plugin-admin2#100).
     *
     * The preview is a plain browser navigation to the real front-end URL, so it
     * can't carry an auth header and deliberately runs with the front-end session
     * suppressed (admin2#88/#79). This endpoint is where the authorization
     * actually happens: it requires read permission on pages and confirms the
     * page exists, then hands back a signed token pinned to this one route. The
     * front-end request presents that token and the plugin force-publishes only
     * this page (see ApiPlugin::onPagesInitialized()). No token, no draft preview
     * — so `?admin_preview=1` alone can never expose an unpublished page.
     */
    public function previewToken(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->config->get('plugins.api.allow_draft_preview', true)) {
            throw new ForbiddenException('Draft preview is disabled for this site.');
        }

        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_READ);
            $this->authorizePageAction($request, $page, 'read', self::PERMISSION_READ);

            // Pin the token to the page's canonical public route — the same value
            // the admin builds the preview URL from — so it can only ever unlock
            // this page. Only super admins and users with page-read can reach here.
            $jwt = new JwtAuthenticator($this->grav, $this->config);
            $ttl = max(30, (int) $this->config->get('plugins.api.preview_token_ttl', 300));
            $token = $jwt->generatePreviewToken($this->getUser($request), $page->route(), $ttl);

            return ApiResponse::create([
                'token' => $token,
                'expires_in' => $ttl,
            ]);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * Parent route of a page route, always using `/` as the separator.
     *
     * PHP's dirname()/basename() use the host OS directory separator, so on
     * Windows dirname('/foo') returns '\' rather than '/'. That made the
     * root-level "parent" check below fail with "Parent page not found at
     * route: \" and broke creating, copying or moving a page at the site root
     * on Windows (getgrav/grav-plugin-admin2#82). Routes are always `/`-
     * delimited, so split them ourselves.
     */
    private static function routeParent(string $route): string
    {
        $route = rtrim($route, '/');
        $pos = strrpos($route, '/');

        return ($pos === false || $pos === 0) ? '/' : substr($route, 0, $pos);
    }

    /**
     * Last segment of a page route, always using `/` as the separator.
     * See {@see routeParent()} for why dirname()/basename() can't be used.
     */
    private static function routeBasename(string $route): string
    {
        $route = rtrim($route, '/');
        $pos = strrpos($route, '/');

        return $pos === false ? $route : substr($route, $pos + 1);
    }

    /**
     * Structural route of a page's parent, resolved from the real hierarchy.
     *
     * Never derive a parent by string-splitting the public route(): when
     * `system.home.hide_in_urls` is on, a child of the home page has its
     * home segment stripped from route() (e.g. '/child' instead of
     * '/home/child'), so routeParent(route()) collapses to '/', which the
     * move/copy/reorganize code then treats as the pages filesystem root —
     * physically relocating the page out of home (getgrav/grav-plugin-admin2#132).
     * The parent's rawRoute() carries the real structural segment ('/home'),
     * and a genuine top-level page reports '/' because its parent is the
     * pages-root. Mirrors the hierarchy-based logic in {@see isDirectChildOf()}.
     *
     * Falls back to string-splitting the public route only for objects with no
     * materialized parent pointer (e.g. test doubles); real pages always carry
     * one, so the hidden-home fix always takes the hierarchy path in practice.
     */
    private static function structuralParentRoute(object $page): string
    {
        if (method_exists($page, 'parent')) {
            $parent = $page->parent();
            if ($parent !== null) {
                if (method_exists($parent, 'root') && $parent->root()) {
                    return '/';
                }
                if (method_exists($parent, 'rawRoute') && $parent->rawRoute()) {
                    return $parent->rawRoute();
                }
            }
        }

        return self::routeParent($page->route());
    }

    /**
     * POST /pages - Create a new page.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        // Authorized further down, against the PARENT page: a `create` rule in
        // frontmatter governs what may be added beneath that page. Until the
        // parent is known, only the shape of the request is validated.
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['route', 'title']);

        // Language can come from body or query param
        $lang = $body['lang'] ?? null;
        $previousLang = $this->applyLanguage($request, $lang);

        try {
            $this->enablePages();

            $route = '/' . trim($body['route'], '/');
            $template = $body['template'] ?? 'default';
            $title = $body['title'];
            $content = $body['content'] ?? '';
            $header = $body['header'] ?? [];
            $order = $body['order'] ?? null;

            // `kind` mirrors classic admin's three-way split:
            //   - 'page'   (default): folder + <template>.md inside (current behaviour)
            //   - 'folder': folder only, no .md file written — useful as a
            //               routing/grouping container
            //   - 'module': folder for a modular sub-page; the slug is prefixed
            //               with `_` (per Grav's modular convention) unless the
            //               caller already supplied that prefix
            $kind = strtolower((string) ($body['kind'] ?? 'page'));
            if (!in_array($kind, ['page', 'folder', 'module'], true)) {
                throw new ValidationException("Invalid 'kind' value: must be one of page, folder, module.");
            }

            // Ensure parent exists
            $parentRoute = self::routeParent($route);
            $slug = self::routeBasename($route);

            // Modular sub-page convention: folder name starts with `_`.
            if ($kind === 'module' && !str_starts_with($slug, '_')) {
                $slug = '_' . $slug;
                $route = ($parentRoute === '/' ? '' : $parentRoute) . '/' . $slug;
            }

            if ($parentRoute !== '/') {
                $parent = $this->grav['pages']->find($parentRoute);
                if (!$parent) {
                    // Don't confirm which parents exist to a caller who can't
                    // create pages anywhere.
                    $this->requirePermission($request, self::PERMISSION_WRITE);
                    throw new ValidationException("Parent page not found at route: {$parentRoute}");
                }
                $parentPath = $parent->path();
            } else {
                // Top level: the pages root can still carry rules of its own.
                $parent = method_exists($this->grav['pages'], 'root')
                    ? $this->grav['pages']->root()
                    : null;
                $parentPath = $this->grav['locator']->findResource('page://', true);
            }

            $this->authorizePageAction($request, $parent, 'create', self::PERMISSION_WRITE);

            // Resolve `order: "auto"` against existing siblings: if any sibling
            // carries a numeric prefix, assign the next number; otherwise leave
            // the new page unprefixed. Mirrors admin-classic's add-page flow.
            if (is_string($order) && strtolower($order) === 'auto') {
                $order = $this->nextOrderInParent($parentPath);
            }

            // Build directory name with optional ordering prefix. Width follows
            // the parent's existing children when present, so adding a page
            // under a 3-digit collection stays 3-digit.
            $dirName = $order !== null ? PageOrdering::key($order, $slug, $this->siblingDigits($parentPath)) : $slug;
            $pagePath = $parentPath . '/' . $dirName;

            if (is_dir($pagePath)) {
                throw new ValidationException("A page already exists at route: {$route}");
            }

            // Build header: blueprint field defaults sit lowest, then the
            // title, then anything the client explicitly sent on top. This
            // restores Grav 1.7 parity — a new page picks up its template's
            // `default:` values (e.g. `header.published: false`,
            // `header.date: ''`) instead of going live with empty frontmatter
            // (admin2#49).
            $header = array_replace_recursive(
                $this->blueprintHeaderDefaults($template),
                ['title' => $title],
                $header,
            );

            // Enforce security.twig_content.* gate before any plugin event can
            // mutate the header — reject the create up-front if the request
            // wants process.twig:true and the user isn't allowed.
            $this->guardTwigContent($request, null, $header);

            // Fire before event — plugins can modify $header/$content or throw to cancel
            $this->fireEvent('onApiBeforePageCreate', [
                'route' => $route,
                'header' => &$header,
                'content' => &$content,
                'template' => &$template,
                'lang' => $lang,
            ]);

            // Allow plugins to inject frontmatter fields (e.g. auto-date plugin)
            $this->fireAdminEvent('onAdminCreatePageFrontmatter', [
                'header' => &$header,
                'data' => $body,
            ]);

            if ($kind === 'folder') {
                // Folder-only page: create the directory with no .md inside.
                // Grav treats such folders as routing/grouping containers.
                if (!is_dir($pagePath)) {
                    if (!@mkdir($pagePath, 0775, true) && !is_dir($pagePath)) {
                        throw new \RuntimeException("Failed to create folder at: {$pagePath}");
                    }
                }
                $page = null;
            } else {
                // Build filename with language extension if applicable
                $filename = $this->buildPageFilename($template, $lang);

                $page = new Page();
                $page->filePath($pagePath . '/' . $filename);
                $page->header((object) $header);
                $page->rawMarkdown($content);

                // Allow plugins to modify the page before save (e.g. SEO Magic, mega-frontmatter)
                $this->fireAdminEvent('onAdminSave', ['object' => &$page, 'page' => &$page]);

                // Validate the submitted page fields against the blueprint (admin2#30).
                $this->validatePageChanges($page, ['header' => $header, 'content' => $content]);

                $page->save();
            }

            $this->clearPagesCache();

            // Re-init pages and fetch the newly created page for serialization
            $this->enablePages(true);
            $newPage = $this->grav['pages']->find($route);

            $resolved = $newPage ?? $page;
            if ($resolved !== null) {
                $this->fireAdminEvent('onAdminAfterSave', ['object' => $resolved, 'page' => $resolved]);
                $this->fireEvent('onApiPageCreated', ['page' => $resolved, 'route' => $route, 'lang' => $lang]);
            }

            // Folder-only pages (no .md) may not surface as a Page object after
            // re-init in every theme/setup. Return a minimal payload in that
            // case rather than 500-ing on the serializer.
            $data = $resolved !== null
                ? $this->serializer->serialize($resolved)
                : ['route' => $route, 'kind' => $kind];
            if ($resolved !== null) {
                $data['permissions'] = $this->pageCapabilities($request, $resolved);
            }
            $location = $this->getApiBaseUrl() . '/pages' . $route;

            return ApiResponse::created(
                $data,
                $location,
                $this->invalidationHeaders(['pages:create:' . $route, 'pages:list']),
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * Resolve the `header.*` portion of a page template's blueprint defaults.
     *
     * Loading via Pages::blueprints() fires `onBlueprintCreated`, so theme- and
     * plugin-supplied blueprints (including `extends@` chains) are fully merged
     * before defaults are extracted. Only the `header` slice is frontmatter —
     * field defaults like `content`/`order`/`slug` are intentionally excluded.
     *
     * Two filters reproduce Grav 1.7's form-based create, which only persisted
     * the fields an author explicitly opted in:
     *
     *  - `toggleable` fields are skipped. Their `default:` is a placeholder the
     *    form shows until the field is toggled on, not a value to persist. Core
     *    and plugin structural fields — `process`, `child_type`,
     *    `admin.children_display_order`, etc. — are all `toggleable: true`, so
     *    they stayed out of new frontmatter; honouring the flag does the same.
     *  - Empty defaults (`null`, `''`, `[]`) are dropped, matching 1.7's filter
     *    (`keepEmptyValues = false`). This sheds non-toggleable-but-empty plugin
     *    noise such as sitemap's `lastmod`/`changefreq`/`priority: ''`. `false`
     *    and `0` are kept — `header.published: { default: false }` must survive.
     *
     * Together these stop a new page from inheriting the entire merged schema
     * (admin2#53) while preserving the author's real defaults (admin2#49).
     *
     * @return array<string, mixed>
     */
    private function blueprintHeaderDefaults(string $template): array
    {
        try {
            $blueprint = $this->grav['pages']->blueprints($template);
            $schema = $blueprint->schema();
            // Force the dynamic fields (`data-default@` etc.) to resolve so the
            // walk below sees the same `default:` values getDefaults() would.
            $blueprint->getDefaults();
            $nested = $schema->getState()['nested'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        $defaults = $this->collectNonToggleableDefaults($nested, $schema);

        return is_array($defaults['header'] ?? null) ? $defaults['header'] : [];
    }

    /**
     * Walk a blueprint's nested field map and collect `default:` values,
     * skipping any field flagged `toggleable: true`.
     *
     * Mirrors BlueprintSchema::buildDefaults(), but the toggleable guard keeps
     * opt-in fields out of the result. Leaves in `$nested` are the dotted field
     * keys (e.g. `header.published`) used to look the rule up on the schema.
     *
     * @param array<string, mixed> $nested
     * @return array<string, mixed>
     */
    private function collectNonToggleableDefaults(array $nested, $schema): array
    {
        $defaults = [];

        foreach ($nested as $key => $value) {
            if ($key === '*') {
                continue;
            }

            if (is_array($value)) {
                $list = $this->collectNonToggleableDefaults($value, $schema);
                if (!empty($list)) {
                    $defaults[$key] = $list;
                }
                continue;
            }

            $field = $schema->get($value);
            if (!is_array($field) || !empty($field['toggleable'])) {
                continue;
            }

            if (array_key_exists('default', $field) && !$this->isEmptyDefault($field['default'])) {
                $defaults[$key] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * Whether a blueprint default should be treated as "no value" and skipped.
     *
     * Mirrors Grav 1.7's filter dropping empty values: `null`, an empty string
     * and an empty array are noise. Crucially `false` and `0` are NOT empty —
     * `header.published: false` is the whole point of admin2#49.
     */
    private function isEmptyDefault(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * PATCH /pages/{route} - Partial update of a page.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
            $this->authorizePageAction($request, $page, 'update', self::PERMISSION_WRITE);

            // Guard against writing to a non-existent translation file. When
            // ?lang=X is specified but no X translation exists, Grav's fallback
            // would silently resolve to the source language and clobber it.
            // Force callers to create the translation via POST /translate first.
            $query = $request->getQueryParams();
            $requestedLang = $query['lang'] ?? null;
            if ($requestedLang && $this->isMultiLangEnabled()) {
                $pageLang = $page->language() ?: null;
                // A default.md file (no language suffix) is treated as the default language
                $defaultLang = $this->grav['language']->getDefault() ?: 'en';
                if (!$pageLang && $requestedLang === $defaultLang) {
                    $pageLang = $defaultLang;
                }
                if ($pageLang !== $requestedLang) {
                    throw new ValidationException(
                        "No '{$requestedLang}' translation exists for this page. "
                        . "Use POST /pages/{$route}/translate to create it first."
                    );
                }
            }

            // ETag validation for conflict detection
            $currentData = $this->serializer->serialize($page);
            $this->validateEtag($request, $this->generateEtag($currentData));

            $body = $this->getRequestBody($request);

            // Enforce security.twig_content.* gate against the incoming header
            // and the existing page state, before any plugin event can mutate
            // either. Covers two cases: user tries to flip process.twig:true,
            // or user tries to edit a page that already has it on.
            $this->guardTwigContent($request, $page, (array) ($body['header'] ?? []));

            // Fire before event — plugins can modify $body or throw to cancel
            $this->fireEvent('onApiBeforePageUpdate', ['page' => $page, 'data' => &$body]);

            if (array_key_exists('content', $body)) {
                $page->rawMarkdown($body['content']);
            }

            if (array_key_exists('title', $body)) {
                $header = $this->headerToArray($page->header());
                $header['title'] = $body['title'];
                $page->header((object) $header);
            }

            if (array_key_exists('header', $body)) {
                $incoming = (array) $body['header'];
                if (($body['header_mode'] ?? null) === 'replace') {
                    // Expert (raw-frontmatter) mode sends the COMPLETE header, so
                    // replace it wholesale. Merging would preserve keys the user
                    // deleted from the YAML — including nested ones — so they'd
                    // reappear after the save (admin2#102). Nulls are still
                    // stripped so an explicit `key: null` removes rather than sets.
                    $merged = $this->stripNullValues($incoming);
                } else {
                    // Normal mode sends a partial header (blueprint-field deltas),
                    // so merge over the existing header; a null value signals
                    // removal of that key (toggleable fields, staged deletions).
                    $existing = $this->headerToArray($page->header());
                    $merged = $this->mergePatch($existing, $incoming);
                    $merged = $this->stripNullValues($merged);
                }
                $page->header((object) $merged);
                // Sync properties that legacy Page caches separately from the
                // header dict (otherwise they stay stale until reload).
                if (array_key_exists('published', $incoming)) {
                    $page->published((bool) $incoming['published']);
                }
                if (array_key_exists('visible', $incoming)) {
                    $page->visible((bool) $incoming['visible']);
                }
            }

            // Template change requires renaming the page file (e.g. default.md → post.md)
            $templateChanged = false;
            $oldFilePath = null;
            if (array_key_exists('template', $body) && $body['template'] !== $page->template()) {
                // The page FILENAME is the template basename only. For modular
                // modules Grav's template() returns a `modular/<name>` form, so
                // feeding that straight into name()/the old path would write
                // `…/modular/<name>.md` — a phantom "modular" child folder, and
                // leave the original module untouched (admin2#69). buildPageFilename()
                // strips the prefix (basename) and handles the language extension.
                $lang = $page->language() ?: null;
                $oldFilePath = $page->path() . '/' . $this->buildPageFilename($page->template(), $lang);
                $page->template($body['template']);
                $page->name($this->buildPageFilename($body['template'], $lang));
                $templateChanged = true;
            }

            if (array_key_exists('published', $body)) {
                $header = $this->headerToArray($page->header());
                $header['published'] = (bool) $body['published'];
                $page->header((object) $header);
                // Legacy Page caches $this->published at init and doesn't
                // re-read from the header. Sync the setter so the post-save
                // serializer reflects the new value (avoids a stale "No" in
                // the Page Info sidebar until a reload).
                $page->published((bool) $body['published']);
            }

            if (array_key_exists('visible', $body)) {
                $header = $this->headerToArray($page->header());
                $header['visible'] = (bool) $body['visible'];
                $page->header((object) $header);
                $page->visible((bool) $body['visible']);
            }

            // Allow plugins to modify the page before save
            $this->fireAdminEvent('onAdminSave', ['object' => &$page, 'page' => &$page]);

            // Validate the submitted page fields against the blueprint before
            // writing to disk (admin2#30) — a required field sent empty now
            // returns 422 instead of saving silently.
            $this->validatePageChanges($page, $body);

            $page->save();

            // Remove old template file after successful save
            if ($templateChanged && $oldFilePath && file_exists($oldFilePath)) {
                unlink($oldFilePath);
            }

            $this->clearPagesCache();

            $this->fireAdminEvent('onAdminAfterSave', ['object' => $page, 'page' => $page]);
            $this->fireEvent('onApiPageUpdated', ['page' => $page]);

            $data = $this->serializer->serialize($page);
            // ETag from the page state alone — see show() for why the caller's
            // capabilities must stay out of it.
            $etag = $this->generateEtag($data);
            $data['permissions'] = $this->pageCapabilities($request, $page);

            return $this->respondWithEtag($data, 200, ['pages:update:/' . $route, 'pages:list'], $etag);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * DELETE /pages/{route} - Delete a page.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
            $this->authorizePageAction($request, $page, 'delete', self::PERMISSION_WRITE);

            $query = $request->getQueryParams();
            $lang = $query['lang'] ?? null;
            $includeChildren = filter_var($query['children'] ?? true, FILTER_VALIDATE_BOOLEAN);

            // If a specific language is requested, delete only that language file
            if ($lang && $this->isMultiLangEnabled()) {
                $this->fireEvent('onApiBeforePageDelete', ['page' => $page, 'lang' => $lang]);

                $this->deleteLanguageFile($page, $lang);
                $this->clearPagesCache();

                $this->fireAdminEvent('onAdminAfterDelete', ['object' => $page, 'page' => $page]);
                $this->fireEvent('onApiPageDeleted', ['route' => '/' . $route, 'lang' => $lang]);

                return ApiResponse::noContent(
                    $this->invalidationHeaders(['pages:delete:/' . $route, 'pages:list']),
                );
            }

            if (!$includeChildren && $page->children()->count() > 0) {
                throw new ValidationException(
                    'This page has children. Use ?children=true to confirm deletion of the page and all its children.'
                );
            }

            $this->fireEvent('onApiBeforePageDelete', ['page' => $page]);

            $pagePath = $page->path();
            Folder::delete($pagePath);

            $this->clearPagesCache();

            $this->fireAdminEvent('onAdminAfterDelete', ['object' => $page, 'page' => $page]);
            $this->fireEvent('onApiPageDeleted', ['route' => '/' . $route]);

            return ApiResponse::noContent(
                $this->invalidationHeaders(['pages:delete:/' . $route, 'pages:list']),
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * POST /pages/{route}/move - Move a page to a new location.
     */
    public function move(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
        // Moving a page rewrites it in place, so it takes `update` on the page —
        // the same action core's Flex listing gates move/copy on — plus
        // `create` on wherever it lands (checked once the destination is known).
        $this->authorizePageAction($request, $page, 'update', self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['parent']);

        $newParentRoute = '/' . trim($body['parent'], '/');

        // The slug becomes a single page-folder name, never a path. Reject any
        // separator, parent-traversal or null byte before it reaches the
        // filesystem: Folder::move() has no containment check of its own, so an
        // unvalidated slug like '01.home/../../../tmp/evil' would relocate the
        // page directory outside user/pages (GHSA-qjq4-jp55-4mx2).
        $rawSlug = $body['slug'] ?? $page->slug();
        $this->assertSinglePathSegment($rawSlug, 'slug');
        $newSlug = ltrim($rawSlug, '.');
        if ($newSlug === '') {
            throw new ValidationException('Invalid slug: must not be empty.');
        }
        // $page->order() returns the matched prefix INCLUDING the trailing
        // dot (e.g. '04.'), not a plain number. Concatenating that with the
        // dot in $dirName produces double-dot folder names like
        // '04..slug' — which then makes Grav read the slug as '.slug'.
        // Normalize to an int (or null when no prefix exists) so the
        // body['order'] contract and the fallback agree on shape.
        if (array_key_exists('order', $body)) {
            $newOrder = $body['order'];
        } else {
            $currentOrder = $page->order();
            $newOrder = ($currentOrder === false || $currentOrder === '' || $currentOrder === null)
                ? null
                : (int) rtrim((string) $currentOrder, '.');
        }

        // Resolve new parent path
        if ($newParentRoute === '/') {
            $newParent = method_exists($this->grav['pages'], 'root')
                ? $this->grav['pages']->root()
                : null;
            $newParentPath = $this->grav['locator']->findResource('page://', true);
        } else {
            $newParent = $this->grav['pages']->find($newParentRoute);
            if (!$newParent) {
                throw new ValidationException("Destination parent not found at route: {$newParentRoute}");
            }
            $newParentPath = $newParent->path();
        }

        $this->authorizePageAction($request, $newParent, 'create', self::PERMISSION_WRITE);

        // Build new directory name
        $dirName = $newOrder !== null
            ? str_pad((string) $newOrder, 2, '0', STR_PAD_LEFT) . '.' . $newSlug
            : $newSlug;

        $oldPath = $page->path();
        $newPath = $newParentPath . '/' . $dirName;

        if ($oldPath === $newPath) {
            throw new ValidationException('Source and destination paths are identical.');
        }

        if (is_dir($newPath)) {
            throw new ValidationException("A page already exists at the destination path.");
        }

        Folder::move($oldPath, $newPath);
        $this->clearPagesCache();

        $this->fireAdminEvent('onAdminAfterSaveAs', ['path' => $newPath]);

        // Re-init and find the moved page
        $this->enablePages(true);
        $newRoute = $newParentRoute === '/' ? '/' . $newSlug : $newParentRoute . '/' . $newSlug;
        $movedPage = $this->grav['pages']->find($newRoute);

        $this->fireEvent('onApiPageMoved', [
            'page' => $movedPage,
            'old_route' => '/' . $route,
            'new_route' => $newRoute,
        ]);

        $moveTags = ['pages:move:/' . $route, 'pages:update:' . $newRoute, 'pages:list'];

        if (!$movedPage) {
            // Fallback: return minimal data if page can't be found at expected route
            return ApiResponse::create(
                ['route' => $newRoute, 'slug' => $newSlug],
                200,
                $this->invalidationHeaders($moveTags),
            );
        }

        $data = $this->serializer->serialize($movedPage);
        $etag = $this->generateEtag($data);
        $data['permissions'] = $this->pageCapabilities($request, $movedPage);

        return $this->respondWithEtag($data, 200, $moveTags, $etag);
    }

    /**
     * POST /pages/{route}/copy - Copy a page to a new location.
     */
    public function copy(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
        // Copying reads the source and creates at the destination.
        $this->authorizePageAction($request, $page, 'read', self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['route']);

        $destRoute = '/' . trim($body['route'], '/');
        $destSlug = self::routeBasename($destRoute);
        $destParentRoute = self::routeParent($destRoute);

        // Resolve destination parent path
        if ($destParentRoute === '/') {
            $destParent = method_exists($this->grav['pages'], 'root')
                ? $this->grav['pages']->root()
                : null;
            $destParentPath = $this->grav['locator']->findResource('page://', true);
        } else {
            $destParent = $this->grav['pages']->find($destParentRoute);
            if (!$destParent) {
                throw new ValidationException("Destination parent not found at route: {$destParentRoute}");
            }
            $destParentPath = $destParent->path();
        }

        $this->authorizePageAction($request, $destParent, 'create', self::PERMISSION_WRITE);

        $destPath = $destParentPath . '/' . $destSlug;

        if (is_dir($destPath)) {
            throw new ValidationException("A page already exists at route: {$destRoute}");
        }

        $sourcePath = $page->path();
        Folder::copy($sourcePath, $destPath);
        $this->rewriteCopiedSlug($destPath, $destSlug, $page->extension());
        $this->clearPagesCache();

        // Re-init and find the copied page
        $this->enablePages(true);
        $copiedPage = $this->grav['pages']->find($destRoute);

        // A copy produces a new page, so it announces itself exactly like
        // create() does. This endpoint fired nothing at all before (#23).
        if ($copiedPage) {
            $this->fireAdminEvent('onAdminAfterSave', ['object' => $copiedPage, 'page' => $copiedPage]);
        }
        $this->fireEvent('onApiPageCreated', [
            'page' => $copiedPage,
            'route' => $destRoute,
            'source_route' => '/' . $route,
            'method' => 'copy',
        ]);

        $copyTags = ['pages:create:' . $destRoute, 'pages:list'];

        if (!$copiedPage) {
            return ApiResponse::created(
                ['route' => $destRoute, 'slug' => $destSlug],
                $this->getApiBaseUrl() . '/pages' . $destRoute,
                $this->invalidationHeaders($copyTags),
            );
        }

        $data = $this->serializer->serialize($copiedPage);
        $data['permissions'] = $this->pageCapabilities($request, $copiedPage);
        $location = $this->getApiBaseUrl() . '/pages' . $destRoute;

        return ApiResponse::created($data, $location, $this->invalidationHeaders($copyTags));
    }

    /**
     * GET /pages/{route}/languages - List available and missing translations for a page.
     */
    public function languages(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_READ);
        $this->authorizePageAction($request, $page, 'read', self::PERMISSION_READ);

        $translated = $page->translatedLanguages();
        $untranslated = $page->untranslatedLanguages();

        /** @var Language $language */
        $language = $this->grav['language'];

        $data = [
            'route' => $page->route(),
            'default_language' => $language->getDefault() ?: null,
            'translated' => $translated,
            'untranslated' => $untranslated,
        ];

        return ApiResponse::create($data);
    }

    /**
     * POST /pages/{route}/translate - Create a new translation for an existing page.
     */
    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
        $this->authorizePageAction($request, $page, 'update', self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['lang']);

        $lang = $body['lang'];
        $this->validateLanguageCode($lang);

        // Check if translation already exists
        $translated = $page->translatedLanguages();
        if (isset($translated[$lang])) {
            throw new ValidationException("A translation already exists for language '{$lang}'. Use PATCH to update it.");
        }

        $title = $body['title'] ?? $page->title();
        $content = $body['content'] ?? $page->rawMarkdown();
        $header = $body['header'] ?? $this->headerToArray($page->header());

        // Ensure title is set, and back-fill blueprint defaults underneath so a
        // minimal explicit header still gets the template's `default:` values
        // (admin2#49). When no header is supplied the source page's frontmatter
        // is already complete, so the defaults are a no-op there.
        $header = array_replace_recursive(
            $this->blueprintHeaderDefaults($page->template()),
            ['title' => $title],
            is_array($header) ? $header : [],
        );

        // Same Twig-content scope/permission cap create()/update() enforce: an
        // api.pages.write key scoped below admin.pages_twig must not enable
        // process.twig on the page it authors here. translate() previously wrote
        // a caller-supplied header/content without this gate, letting a scoped key
        // turn on Twig-in-content (SSTI) that create()/update() would reject.
        // (GHSA-w94c-jmg4-w4c9, follow-up to GHSA-96xv-p87j-58mx)
        $this->guardTwigContent($request, null, is_array($body['header'] ?? null) ? $body['header'] : []);

        $this->fireEvent('onApiBeforePageTranslate', [
            'page' => $page,
            'lang' => $lang,
            'header' => &$header,
            'content' => &$content,
        ]);

        // Build the language-specific file path
        $template = $page->template();
        $filename = $this->buildPageFilename($template, $lang);
        $filePath = $page->path() . '/' . $filename;

        $translatedPage = new Page();
        $translatedPage->filePath($filePath);
        $translatedPage->header((object) $header);
        $translatedPage->rawMarkdown($content);

        // Editor XSS backstop, as run by create()/update(). (GHSA-w94c-jmg4-w4c9)
        $this->validatePageChanges($translatedPage, ['header' => $header, 'content' => $content]);

        // Allow plugins to modify the page before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$translatedPage, 'page' => &$translatedPage]);

        $translatedPage->save();

        $this->clearPagesCache();

        // Re-init and fetch the translated page
        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;

        try {
            // The file was written explicitly above, so this switch only affects
            // what gets echoed back — but without it the response (and the
            // events below) carried the source-language page (#24).
            $this->switchLanguage($lang);
            $newPage = $this->grav['pages']->find('/' . $route);

            $this->fireAdminEvent('onAdminAfterSave', ['object' => $newPage ?? $translatedPage, 'page' => $newPage ?? $translatedPage]);
            $this->fireEvent('onApiPageTranslated', [
                'page' => $newPage ?? $translatedPage,
                'route' => '/' . $route,
                'lang' => $lang,
            ]);

            $data = $this->serializer->serialize($newPage ?? $translatedPage);
            $data['permissions'] = $this->pageCapabilities($request, $newPage ?? $translatedPage);
            $location = $this->getApiBaseUrl() . '/pages/' . $route;

            return ApiResponse::created(
                $data,
                $location,
                $this->invalidationHeaders(['pages:update:/' . $route, 'pages:list']),
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * POST /pages/{route}/adopt-language — Claim an untyped default page file
     * (e.g., "default.md") as belonging to a specific language by renaming it
     * in-place to "{template}.{lang}.md". Does not modify contents; pure
     * filesystem rename + cache bust.
     *
     * Useful for sites that started single-language (bare default.md) and later
     * enabled multilang — lets the operator declare "this existing content is
     * the English version" without editing YAML or re-saving the page.
     *
     * Fails if the page already has an explicit file for that language, or if
     * no untyped base file exists.
     */
    public function adoptLanguage(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
        $this->authorizePageAction($request, $page, 'update', self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['lang']);

        $lang = (string) $body['lang'];
        $this->validateLanguageCode($lang);

        if (!$this->isMultiLangEnabled()) {
            throw new ValidationException('Multi-language is not enabled for this site.');
        }

        $template = $page->template();
        $pageDir = $page->path();
        if (!$pageDir || !is_dir($pageDir)) {
            throw new NotFoundException("Page directory not found for route: /{$route}");
        }

        $baseFile = $pageDir . '/' . $template . '.md';
        if (!is_file($baseFile)) {
            throw new ValidationException(
                "No untyped base file ({$template}.md) found for route /{$route}. "
                . 'Page already uses language-suffixed files — use POST /pages/{route}/translate for new languages.'
            );
        }

        // Block only if an EXPLICIT language file already exists. We can't use
        // $page->translatedLanguages() because Grav reports the default lang
        // as "translated" whenever default.md exists (the fallback). The
        // question we actually need to answer is: does default.<lang>.md
        // exist on disk?
        $targetFilename = $this->buildPageFilename($template, $lang);
        $targetFile = $pageDir . '/' . $targetFilename;
        if (is_file($targetFile)) {
            throw new ValidationException("A translation file already exists for language '{$lang}'.");
        }

        // If the config resolves to the same filename (unlikely, but guard),
        // there's nothing to do — fail cleanly rather than nop-renaming.
        if (realpath($baseFile) === realpath($targetFile)) {
            throw new ValidationException(
                'Target filename resolves to the same path as the base file — '
                . 'check system.languages.include_default_lang_file_extension.'
            );
        }

        $this->fireEvent('onApiBeforePageAdoptLanguage', [
            'page' => $page,
            'route' => '/' . $route,
            'lang' => $lang,
            'from_file' => $baseFile,
            'to_file' => $targetFile,
        ]);

        if (!@rename($baseFile, $targetFile)) {
            throw new ApiException(500, 'Rename Failed', "Failed to rename '{$baseFile}' to '{$targetFile}'.");
        }

        $this->clearPagesCache();

        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;

        try {
            $this->switchLanguage($lang);
            $newPage = $this->grav['pages']->find('/' . $route);

            $this->fireEvent('onApiPageLanguageAdopted', [
                'page' => $newPage ?? $page,
                'route' => '/' . $route,
                'lang' => $lang,
            ]);

            $data = $this->serializer->serialize($newPage ?? $page);
            $data['permissions'] = $this->pageCapabilities($request, $newPage ?? $page);

            return ApiResponse::create(
                $data,
                200,
                $this->invalidationHeaders(['pages:update:/' . $route, 'pages:list']),
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * GET /languages - List all configured site languages.
     */
    public function siteLanguages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);

        /** @var Language $language */
        $language = $this->grav['language'];

        if (!$language->enabled()) {
            return ApiResponse::create([
                'enabled' => false,
                'languages' => [],
                'default' => null,
                'active' => null,
            ]);
        }

        $langs = $language->getLanguages();
        $default = $language->getDefault() ?: null;

        $languageDetails = [];
        foreach ($langs as $code) {
            $languageDetails[] = [
                'code' => $code,
                'name' => LanguageCodes::getName($code) ?: $code,
                'native_name' => LanguageCodes::getNativeName($code) ?: $code,
                'rtl' => LanguageCodes::isRtl($code),
                'is_default' => $code === $default,
            ];
        }

        $data = [
            'enabled' => true,
            'languages' => $languageDetails,
            'default' => $default,
            'active' => $language->getActive() ?: $default,
        ];

        return ApiResponse::create($data);
    }

    /**
     * POST /pages/{route}/sync - Sync/reset a translation from another language.
     * Copies content and header from source language to target language.
     */
    public function sync(ServerRequestInterface $request): ResponseInterface
    {
        // Authorized against the page itself, once the source language is loaded.
        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['source_lang', 'target_lang']);

        $sourceLang = $body['source_lang'];
        $targetLang = $body['target_lang'];
        $this->validateLanguageCode($sourceLang);
        $this->validateLanguageCode($targetLang);

        if ($sourceLang === $targetLang) {
            throw new ValidationException('Source and target languages must be different.');
        }

        $route = $this->getRouteParam($request, 'route');

        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;

        try {
            // Load the source page
            $this->switchLanguage($sourceLang);
            $sourcePage = $this->grav['pages']->find('/' . $route);

            if (!$sourcePage) {
                $this->requirePermission($request, self::PERMISSION_WRITE);
                throw new NotFoundException("Page not found at route '/{$route}' for source language '{$sourceLang}'.");
            }

            $this->authorizePageAction($request, $sourcePage, 'update', self::PERMISSION_WRITE);

            $sourceContent = $sourcePage->rawMarkdown();
            $sourceHeader = $this->headerToArray($sourcePage->header());

            // Load the target page. The rebuild here is what makes the write
            // land on the target translation rather than the source file (#24).
            $this->switchLanguage($targetLang);
            $targetPage = $this->grav['pages']->find('/' . $route);

            if (!$targetPage) {
                throw new NotFoundException("Page not found at route '/{$route}' for target language '{$targetLang}'.");
            }

            // Verify the target translation file actually exists
            $translated = $targetPage->translatedLanguages();
            if (!isset($translated[$targetLang])) {
                throw new ValidationException(
                    "No translation file exists for language '{$targetLang}'. Use POST /pages/{route}/translate to create one first."
                );
            }

            $this->fireEvent('onApiBeforePageSync', [
                'page' => $targetPage,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
                'header' => &$sourceHeader,
                'content' => &$sourceContent,
            ]);

            // Parity with translate()/create()/update(): enforce the Twig-content
            // cap and the editor XSS backstop on this write too. sync() copies from
            // an already-vetted on-disk source rather than the request body, so it
            // is not a standalone injection path, but every page-write entry point
            // should apply the same guards. (GHSA-w94c-jmg4-w4c9)
            $this->guardTwigContent($request, $targetPage, $sourceHeader);

            // Overwrite the target with source data
            $targetPage->header((object) $sourceHeader);
            $targetPage->rawMarkdown($sourceContent);

            $this->validatePageChanges($targetPage, ['header' => $sourceHeader, 'content' => $sourceContent]);

            $this->fireAdminEvent('onAdminSave', ['object' => &$targetPage, 'page' => &$targetPage]);
            $targetPage->save();
            $this->clearPagesCache();

            // Re-fetch the updated page
            $this->enablePages(true);
            $updatedPage = $this->grav['pages']->find('/' . $route);

            $this->fireAdminEvent('onAdminAfterSave', ['object' => $updatedPage ?? $targetPage, 'page' => $updatedPage ?? $targetPage]);
            $this->fireEvent('onApiPageSynced', [
                'page' => $updatedPage ?? $targetPage,
                'route' => '/' . $route,
                'source_lang' => $sourceLang,
                'target_lang' => $targetLang,
            ]);

            $data = $this->serializer->serialize($updatedPage ?? $targetPage);
            $data['permissions'] = $this->pageCapabilities($request, $updatedPage ?? $targetPage);
            return ApiResponse::create(
                $data,
                200,
                $this->invalidationHeaders(['pages:update:/' . $route, 'pages:list']),
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * GET /pages/{route}/compare - Compare two language versions of a page side-by-side.
     */
    public function compare(ServerRequestInterface $request): ResponseInterface
    {
        // Account-wide gate up front (this endpoint reads a page that may not
        // resolve at all); a page-level deny is applied below, once the source
        // page is loaded. Both language variants share the same frontmatter
        // rules, so checking the source covers the pair.
        $this->requirePermission($request, self::PERMISSION_READ);

        $params = $request->getQueryParams();
        $sourceLang = $params['source'] ?? null;
        $targetLang = $params['target'] ?? null;

        if (!$sourceLang || !$targetLang) {
            throw new ValidationException("Both 'source' and 'target' query parameters are required.");
        }

        $this->validateLanguageCode($sourceLang);
        $this->validateLanguageCode($targetLang);

        $route = $this->getRouteParam($request, 'route');

        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;

        try {
            // Load source page
            $this->switchLanguage($sourceLang);
            $sourcePage = $this->grav['pages']->find('/' . $route);

            $sourceData = null;
            if ($sourcePage) {
                $this->assertPageNotDenied($request, $sourcePage, 'read');
                $translated = $sourcePage->translatedLanguages();
                $sourceData = [
                    'lang' => $sourceLang,
                    'exists' => isset($translated[$sourceLang]),
                    'title' => $sourcePage->title(),
                    'content' => $sourcePage->rawMarkdown(),
                    'header' => $this->headerToArray($sourcePage->header()),
                    'modified' => $sourcePage->modified() ? date('c', $sourcePage->modified()) : null,
                ];
            }

            // Load target page. Without the extension-memo reset inside
            // switchLanguage() this resolved back to the source file, so the
            // two sides of the comparison were always identical (#24).
            $this->switchLanguage($targetLang);
            $targetPage = $this->grav['pages']->find('/' . $route);

            $targetData = null;
            if ($targetPage) {
                $translated = $targetPage->translatedLanguages();
                $targetData = [
                    'lang' => $targetLang,
                    'exists' => isset($translated[$targetLang]),
                    'title' => $targetPage->title(),
                    'content' => $targetPage->rawMarkdown(),
                    'header' => $this->headerToArray($targetPage->header()),
                    'modified' => $targetPage->modified() ? date('c', $targetPage->modified()) : null,
                ];
            }

            $data = [
                'route' => '/' . $route,
                'source' => $sourceData,
                'target' => $targetData,
            ];

            return ApiResponse::create($data);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * POST /pages/{route}/reorder - Reorder child pages under a parent.
     */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $parent = $this->findPageOrFail('/' . $route, $request, self::PERMISSION_WRITE);
        // Reordering rewrites the children's folder prefixes, which is an
        // update of the container they live in.
        $this->authorizePageAction($request, $parent, 'update', self::PERMISSION_WRITE);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['order']);

        $order = $body['order'];
        if (!is_array($order)) {
            throw new ValidationException("The 'order' field must be an array of child slugs.");
        }

        $this->fireEvent('onApiBeforePagesReorder', ['parent' => $parent, 'order' => $order]);

        $parentPath = $parent->path();
        $children = $parent->children();

        // Build a map of slug -> current directory name
        $childMap = [];
        foreach ($children as $child) {
            $childMap[$child->slug()] = basename($child->path());
        }

        // Validate all slugs exist
        foreach ($order as $slug) {
            if (!isset($childMap[$slug])) {
                throw new ValidationException("Child page with slug '{$slug}' not found under '{$parent->route()}'.");
            }
        }

        // Rename directories with new ordering prefixes. Use the widest existing
        // sibling prefix as the target width so a parent of all-3-digit children
        // stays 3-digit through reorder; otherwise fall back to system default.
        $digits = 0;
        foreach ($childMap as $existingDir) {
            $w = PageOrdering::digitsFromFolder($existingDir);
            if ($w !== null && $w > $digits) {
                $digits = $w;
            }
        }
        $reorderDigits = $digits ?: null;

        $tempRenames = [];
        $position = 1;

        foreach ($order as $slug) {
            $currentDir = $childMap[$slug];
            $newDir = PageOrdering::key($position, $slug, $reorderDigits);

            if ($currentDir !== $newDir) {
                $oldPath = $parentPath . '/' . $currentDir;
                // Use temp name to avoid conflicts during rename
                $tempPath = $parentPath . '/_temp_' . $position . '_' . $slug;
                $tempRenames[] = [
                    'temp' => $tempPath,
                    'final' => $parentPath . '/' . $newDir,
                    'old' => $oldPath,
                ];
                if (is_dir($oldPath)) {
                    rename($oldPath, $tempPath);
                }
            }

            $position++;
        }

        // Now rename from temp to final names
        foreach ($tempRenames as $rename) {
            if (is_dir($rename['temp'])) {
                rename($rename['temp'], $rename['final']);
            }
        }

        $this->clearPagesCache();

        $this->fireEvent('onApiPagesReordered', ['parent' => $parent, 'order' => $order]);

        // Re-init and return updated children
        $this->enablePages(true);
        $updatedParent = $this->grav['pages']->find('/' . $route);
        $childData = [];
        if ($updatedParent) {
            foreach ($updatedParent->children() as $child) {
                $childData[] = [
                    'route' => $child->route(),
                    'slug' => $child->slug(),
                    'title' => $child->title(),
                    'order' => $child->order(),
                ];
            }
        }

        return ApiResponse::create(
            $childData,
            200,
            $this->invalidationHeaders(['pages:reorder:/' . $route, 'pages:list']),
        );
    }

    /**
     * POST /pages/batch - Batch operations on multiple pages.
     */
    public function batch(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['operation', 'routes']);

        $operation = $body['operation'];
        $routes = $body['routes'];
        $options = $body['options'] ?? [];

        $allowedOps = ['publish', 'unpublish', 'delete', 'copy'];
        if (!in_array($operation, $allowedOps, true)) {
            throw new ValidationException(
                "Invalid operation '{$operation}'. Allowed: " . implode(', ', $allowedOps)
            );
        }

        if (!is_array($routes) || empty($routes)) {
            throw new ValidationException("The 'routes' field must be a non-empty array.");
        }

        $maxBatch = $this->config->get('plugins.api.batch.max_items', 50);
        if (count($routes) > $maxBatch) {
            throw new ValidationException("Batch operations are limited to {$maxBatch} items.");
        }

        // Validate all routes exist first
        $pages = [];
        foreach ($routes as $route) {
            $normalizedRoute = '/' . trim($route, '/');
            $page = $this->grav['pages']->find($normalizedRoute);
            if (!$page) {
                throw new ValidationException("Page not found at route: {$normalizedRoute}");
            }
            $pages[$normalizedRoute] = $page;
        }

        // Which page-level action each operation exercises, so a page that
        // denies it is skipped rather than silently swept up in a bulk call.
        // Only denials are honored here: the caller already cleared the
        // account-wide write gate above, so there is no grant to fall back on.
        $pageActions = match ($operation) {
            'publish', 'unpublish' => ['publish', 'update'],
            'delete' => ['delete'],
            'copy' => ['read'],
        };

        $results = [];
        $copied = [];

        foreach ($pages as $route => $page) {
            try {
                $this->assertPageNotDenied($request, $page, ...$pageActions);
                match ($operation) {
                    'publish' => $this->batchPublish($page, $route, true),
                    'unpublish' => $this->batchPublish($page, $route, false),
                    'delete' => $this->batchDelete($page, $route),
                    'copy' => $copied[$route] = $this->batchCopy($page, $options),
                };
                $results[] = ['route' => $route, 'status' => 'success'];
            } catch (\Throwable $e) {
                $results[] = ['route' => $route, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $this->clearPagesCache();

        // Copies announce themselves once the index knows about them, so
        // listeners get a real page object rather than a bare route. One
        // rebuild covers the whole batch (#23).
        if ($copied !== []) {
            $this->enablePages(true);

            foreach ($copied as $sourceRoute => $destRoute) {
                $newPage = $this->grav['pages']->find($destRoute);
                if ($newPage) {
                    $this->fireAdminEvent('onAdminAfterSave', ['object' => $newPage, 'page' => $newPage]);
                }
                $this->fireEvent('onApiPageCreated', [
                    'page' => $newPage,
                    'route' => $destRoute,
                    'source_route' => $sourceRoute,
                    'method' => 'batch',
                ]);
            }
        }

        // Build per-route invalidations so listeners on specific pages react too.
        // A copy creates a page at the DESTINATION, so that is the route whose
        // cache entry is new — tagging the untouched source instead told
        // clients to refetch the wrong page.
        $tags = ['pages:list'];
        foreach ($results as $r) {
            if ($r['status'] !== 'success') continue;
            $tags[] = match ($operation) {
                'delete' => 'pages:delete:' . $r['route'],
                'copy' => 'pages:create:' . ($copied[$r['route']] ?? $r['route']),
                default => 'pages:update:' . $r['route'],
            };
        }

        return ApiResponse::create(
            [
                'operation' => $operation,
                'results' => $results,
                'total' => count($results),
                'successful' => count(array_filter($results, fn($r) => $r['status'] === 'success')),
                'failed' => count(array_filter($results, fn($r) => $r['status'] === 'error')),
            ],
            200,
            $this->invalidationHeaders($tags),
        );
    }

    /**
     * POST /pages/reorganize - Reorganize multiple pages (move and/or reorder) atomically.
     *
     * Accepts an array of operations, each specifying a page route and optionally
     * a new parent and/or position. All operations are validated before any
     * filesystem changes are applied. Uses a two-phase temp-rename strategy
     * to avoid conflicts.
     */
    public function reorganize(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['operations']);

        $operations = $body['operations'];
        if (!is_array($operations) || empty($operations)) {
            throw new ValidationException("The 'operations' field must be a non-empty array.");
        }

        $maxBatch = $this->config->get('plugins.api.batch.max_items', 50);
        if (count($operations) > $maxBatch) {
            throw new ValidationException("Reorganize operations are limited to {$maxBatch} items.");
        }

        // --- Phase 1: Validate all operations ---
        $resolved = [];
        $seenRoutes = [];
        $affectedParentRoutes = [];

        foreach ($operations as $index => $op) {
            if (!is_array($op) || !isset($op['route'])) {
                throw new ValidationException("Operation at index {$index} must have a 'route' field.");
            }

            $route = '/' . trim($op['route'], '/');

            if (isset($seenRoutes[$route])) {
                throw new ValidationException("Duplicate route '{$route}' in operations.");
            }
            $seenRoutes[$route] = true;

            $page = $this->grav['pages']->find($route);
            if (!$page) {
                throw new ValidationException("Page not found at route: {$route}");
            }

            // Reorganize is all-or-nothing, so a page that denies being moved
            // fails the whole request rather than being quietly skipped.
            $this->assertPageNotDenied($request, $page, 'update');

            $currentParentRoute = self::structuralParentRoute($page);
            $affectedParentRoutes[$currentParentRoute] = true;

            // Resolve destination parent
            $newParentRoute = null;
            $newParentPath = null;
            if (isset($op['parent'])) {
                $newParentRoute = '/' . trim($op['parent'], '/');
                if ($newParentRoute === '/') {
                    $newParentPath = $this->grav['locator']->findResource('page://', true);
                } else {
                    $newParent = $this->grav['pages']->find($newParentRoute);
                    if (!$newParent) {
                        throw new ValidationException("Destination parent not found at route: {$newParentRoute} (operation index {$index}).");
                    }
                    $this->assertPageNotDenied($request, $newParent, 'create');
                    $newParentPath = $newParent->path();
                }
                $affectedParentRoutes[$newParentRoute] = true;

                // Prevent moving a page into its own subtree
                if (str_starts_with($newParentRoute . '/', $route . '/')) {
                    throw new ValidationException("Cannot move '{$route}' into its own subtree '{$newParentRoute}'.");
                }
            } else {
                // Stays under current parent
                $newParentRoute = $currentParentRoute;
                if ($currentParentRoute === '/') {
                    $newParentPath = $this->grav['locator']->findResource('page://', true);
                } else {
                    $currentParent = $this->grav['pages']->find($currentParentRoute);
                    $newParentPath = $currentParent ? $currentParent->path() : null;
                }
            }

            $position = isset($op['position']) ? (int) $op['position'] : null;

            // Validate position conflicts: no two ops targeting same parent with same position
            if ($position !== null) {
                $posKey = $newParentRoute . ':' . $position;
                foreach ($resolved as $prev) {
                    if ($prev['newParentRoute'] === $newParentRoute && $prev['position'] === $position) {
                        throw new ValidationException(
                            "Position conflict: both '{$prev['route']}' and '{$route}' target position {$position} under '{$newParentRoute}'."
                        );
                    }
                }
            }

            // Whether this op actually causes a path rename on disk. Position-
            // unchanged + parent-unchanged ops are no-ops at the filesystem
            // level — clients (e.g. the tree-view drag handler) emit them
            // when renumbering all siblings of a drop target, even for
            // siblings whose position didn't actually shift. Tracking these
            // as "moved" below would falsely flag conflicts when one of those
            // no-op siblings happens to be the source parent of another op.
            $currentOrder = (int) $page->order();
            $parentChanged = $newParentRoute !== $currentParentRoute;
            $positionChanged = $position !== null && $position !== $currentOrder;
            $actuallyMoves = $parentChanged || $positionChanged;

            $resolved[] = [
                'route' => $route,
                'page' => $page,
                // Strip leading dots so pages whose slug somehow starts with
                // '.' don't get rebuilt into '04..slug' style folders.
                // Matches the sanitization the single-page /move endpoint
                // already applies.
                'slug' => ltrim($page->slug(), '.'),
                'oldPath' => $page->path(),
                'currentParentRoute' => $currentParentRoute,
                'newParentRoute' => $newParentRoute,
                'newParentPath' => $newParentPath,
                'position' => $position,
                'actuallyMoves' => $actuallyMoves,
            ];
        }

        // Reject any op whose destination parent is itself being moved in
        // this batch. Otherwise Phase 2 would rename the parent mid-batch,
        // making Phase 3's rename($tempPath, $finalPath) fail because the
        // captured newParentPath no longer exists on disk. Asking the
        // client to drop these ops produces a clear error instead of the
        // confusing "No such file or directory" surface.
        //
        // Only routes that actually rename on disk participate — a no-op
        // renumber (position unchanged, parent unchanged) leaves the folder
        // path intact, so it cannot invalidate a sibling op's newParentPath.
        $movedRoutes = [];
        foreach ($resolved as $op) {
            if ($op['actuallyMoves']) {
                $movedRoutes[$op['route']] = true;
            }
        }
        foreach ($resolved as $index => $op) {
            $parentRoute = $op['newParentRoute'];
            // The parent itself, or any ancestor of it, being moved is
            // unsafe — its on-disk path won't match newParentPath after
            // Phase 2 renames.
            $check = $parentRoute;
            while ($check !== '/' && $check !== '') {
                if (isset($movedRoutes[$check])) {
                    throw new ValidationException(
                        "Operation index {$index} targets parent '{$parentRoute}', but '{$check}' is also being moved in the same batch. Reorganize the parent first, or drop one of the ops."
                    );
                }
                $check = self::routeParent($check);
            }
        }

        $this->fireEvent('onApiBeforePagesReorganize', ['operations' => $resolved]);

        // --- Phase 2: Move to temp names ---
        $completedRenames = [];

        try {
            foreach ($resolved as $index => &$op) {
                $slug = $op['slug'];
                $destParentPath = $op['newParentPath'];
                $tempName = '_reorg_temp_' . $index . '_' . $slug;
                $tempPath = $destParentPath . '/' . $tempName;

                if (is_dir($op['oldPath'])) {
                    Folder::move($op['oldPath'], $tempPath);
                    $completedRenames[] = ['from' => $op['oldPath'], 'to' => $tempPath];
                    $op['tempPath'] = $tempPath;
                } else {
                    $op['tempPath'] = null;
                }
            }
            unset($op);

            // --- Phase 3: Rename from temp to final names ---
            foreach ($resolved as &$op) {
                if (!$op['tempPath']) {
                    continue;
                }

                $slug = $op['slug'];
                $position = $op['position'];
                $destParentPath = $op['newParentPath'];

                $dirName = $position !== null
                    ? str_pad((string) $position, 2, '0', STR_PAD_LEFT) . '.' . $slug
                    : $slug;

                $finalPath = $destParentPath . '/' . $dirName;

                rename($op['tempPath'], $finalPath);
                $completedRenames[] = ['from' => $op['tempPath'], 'to' => $finalPath];
                $op['finalPath'] = $finalPath;
            }
            unset($op);
        } catch (\Throwable $e) {
            // Best-effort rollback: reverse completed renames
            foreach (array_reverse($completedRenames) as $rename) {
                if (is_dir($rename['to'])) {
                    try {
                        Folder::move($rename['to'], $rename['from']);
                    } catch (\Throwable) {
                        // Can't recover further
                    }
                }
            }

            throw new ValidationException("Reorganize failed during filesystem operations: {$e->getMessage()}");
        }

        $this->clearPagesCache();
        $this->enablePages(true);

        $this->fireEvent('onApiPagesReorganized', ['operations' => $resolved]);

        // Plus one per-page move, so a listener written against the single-page
        // /move endpoint picks up reorganize moves too instead of quietly
        // missing them (#23). Ops that didn't actually rename anything on disk
        // (siblings renumbered to the position they already held) are skipped.
        foreach ($resolved as $op) {
            if (empty($op['actuallyMoves'])) {
                continue;
            }

            $parentRoute = (string) $op['newParentRoute'];
            $newRoute = ($parentRoute === '/' ? '' : rtrim($parentRoute, '/')) . '/' . $op['slug'];

            $this->fireEvent('onApiPageMoved', [
                'page' => $this->grav['pages']->find($newRoute),
                'old_route' => $op['route'],
                'new_route' => $newRoute,
                'method' => 'reorganize',
            ]);
        }

        // --- Phase 4: Build response with all affected pages ---
        $affectedData = [];
        foreach (array_keys($affectedParentRoutes) as $parentRoute) {
            // '/' means the pages-root (genuine top-level pages), NOT the home
            // page — find('/') resolves to home when it's the default route, so
            // reporting its children would list home's children instead of the
            // real top-level set (getgrav/grav-plugin-admin2#132).
            $parent = $parentRoute === '/'
                ? $this->grav['pages']->root()
                : $this->grav['pages']->find($parentRoute);

            if (!$parent) {
                continue;
            }

            foreach ($parent->children() as $child) {
                $affectedData[] = [
                    'route' => $child->route(),
                    'slug' => $child->slug(),
                    'title' => $child->title(),
                    'order' => $child->order(),
                    'parent' => $parentRoute,
                ];
            }
        }

        // Emit one update/move tag per reorganized page plus list invalidation
        $tags = ['pages:list'];
        foreach ($resolved as $op) {
            $tags[] = 'pages:move:' . $op['route'];
        }

        return ApiResponse::create(
            $affectedData,
            200,
            $this->invalidationHeaders($tags),
        );
    }

    /**
     * GET /taxonomy - List all taxonomy types and their values.
     */
    public function taxonomy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->enablePages();

        $raw = $this->grav['taxonomy']->taxonomy();

        // Seed every declared taxonomy type (site.taxonomies) so the admin can
        // add categories/tags even when no page uses them yet. Without this the
        // list is empty on fresh pages/sites and nothing can be added.
        $taxonomy = [];
        foreach ((array) $this->grav['config']->get('site.taxonomies', []) as $type) {
            $taxonomy[$type] = [];
        }

        // Simplify: return just taxonomy type => [values] without internal file paths
        foreach ($raw as $type => $values) {
            $taxonomy[$type] = array_keys($values);
        }

        return ApiResponse::create($taxonomy);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enable the Pages subsystem. API disables pages on init for performance,
     * so we re-enable when page endpoints are actually called.
     */
    private function enablePages(bool $forceReinit = false): void
    {
        $pages = $this->grav['pages'];

        if ($forceReinit) {
            $pages->reset();
        }

        // Pages::enablePages() flips the flag and calls init()
        $pages->enablePages();
    }

    /**
     * Stamp each serialized listing row with what this caller may do to that
     * page, so a client can hide the edit/delete affordances a page's own
     * frontmatter denies (getgrav/grav-plugin-admin2#150).
     *
     * Only the paginated slice is annotated, and rows are never dropped — a
     * page whose rules deny reading still appears in the listing without
     * actions, exactly as core's Flex page index behaves.
     *
     * @param list<PageInterface> $pages Pages in the same order they were serialized.
     * @param list<array<string, mixed>> $data
     * @return list<array<string, mixed>>
     */
    private function attachPageCapabilities(ServerRequestInterface $request, array $pages, array $data): array
    {
        $pages = array_values($pages);

        foreach ($data as $index => $item) {
            $page = $pages[$index] ?? null;
            if ($page instanceof PageInterface) {
                $item['permissions'] = $this->pageCapabilities($request, $page);
                $data[$index] = $item;
            }
        }

        return $data;
    }

    /**
     * Find a page by route or throw NotFoundException.
     *
     * Page-level permissions can only be evaluated once the page is in hand, so
     * the endpoints that honor them authorize AFTER this lookup. Pass the
     * request and the endpoint's account-wide permission and a miss is gated
     * too — otherwise a caller with no page permission at all could probe which
     * routes exist by reading 404 vs 403.
     */
    private function findPageOrFail(
        string $route,
        ?ServerRequestInterface $request = null,
        ?string $permission = null,
    ): PageInterface {
        $page = $this->resolvePageByRoute($route);

        if (!$page) {
            if ($request !== null && $permission !== null) {
                $this->requirePermission($request, $permission);
            }
            throw new NotFoundException("Page not found at route: {$route}");
        }

        return $page;
    }

    /**
     * Collect all page instances and apply filters.
     *
     * @param iterable<string, PageInterface> $instances
     * @return list<PageInterface>
     */
    private function collectAndFilterPages(iterable $instances, array $filters): array
    {
        $pages = [];

        foreach ($instances as $page) {
            // Skip the virtual pages-root container (no file on disk).
            // The home page is a real file-backed page with route '/'.
            if (!$page->route() || !$page->exists()) {
                continue;
            }

            if (!$this->matchesFilters($page, $filters)) {
                continue;
            }

            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * Check if a page matches all active filters.
     */
    private function matchesFilters(PageInterface $page, array $filters): bool
    {
        foreach ($filters as $filter => $value) {
            $matches = match ($filter) {
                'published' => $page->published() === filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'template' => $page->template() === $value,
                'routable' => $page->routable() === filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'visible' => $page->visible() === filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'parent' => str_starts_with($page->route(), '/' . trim($value, '/')),
                'children_of' => $this->isDirectChildOf($page, $value),
                // Root-level = direct child of the pages-root, resolved from the
                // real hierarchy (see isDirectChildOf) so home-page children
                // aren't mistaken for top-level pages.
                'root' => filter_var($value, FILTER_VALIDATE_BOOLEAN) && $this->isDirectChildOf($page, '/'),
                default => true,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a page is a direct child of the given parent route.
     *
     * Resolves the relationship from Grav's real page hierarchy
     * ($page->parent()) rather than by string-matching the public route. The
     * home page's children have the home segment stripped from their public
     * route (e.g. '/child' instead of '/home/child' when home.hide_in_urls is
     * on), so a route-string comparison wrongly lists them as children of root
     * — the cause of the tree/columns hierarchy bug
     * (getgrav/grav-plugin-admin2#32). Comparing against the actual parent
     * page, like admin-classic's tree does, keeps the hierarchy correct.
     */
    private function isDirectChildOf(PageInterface $page, string $parentValue): bool
    {
        $parent = $page->parent();
        if ($parent === null) {
            // The virtual pages-root itself has no parent; it's nobody's child.
            return false;
        }

        $parentRoute = '/' . trim($parentValue, '/');

        if ($parentRoute === '/') {
            // Direct child of root: the page's parent IS the pages-root. This
            // correctly excludes children of the home page (whose parent is the
            // home page, a real page), which would otherwise leak into root.
            return $parent->root();
        }

        // Match the real parent by its structural route first (the home page's
        // public route is '/', but its rawRoute is e.g. '/home'), then fall
        // back to the public route for everything else.
        return $parentRoute === $parent->rawRoute()
            || $parentRoute === $parent->route();
    }

    private function indexViaDefaultSort(ServerRequestInterface $request, string $parentRoute, array $filters, array $pagination): ResponseInterface
    {
        // Collect direct children and find parent using Flex or Pages service
        $directory = $this->getFlexDirectory('pages');
        $parent = null;
        $childRoute = '/' . trim($filters['children_of'], '/');

        $items = [];
        if ($directory) {
            foreach ($directory->getCollection() as $page) {
                if (!$page instanceof PageInterface) {
                    continue;
                }
                // Match by rawRoute too: the home page's public route is '/'
                // while the frontend asks for its structural route (e.g. '/home').
                if ($page->route() === $childRoute || $page->rawRoute() === $childRoute) {
                    $parent = $page;
                }
                // matchesFilters() covers children_of (via isDirectChildOf) *and*
                // the published/visible/routable/template filters. Testing only
                // isDirectChildOf here silently dropped those boolean filters on
                // the default-sort path used by the tree and columns views —
                // the same class of bug as getgrav/grav-plugin-admin2#121.
                if ($this->matchesFilters($page, $filters)) {
                    $items[] = $page;
                }
            }
        } else {
            $this->enablePages();
            $parent = $this->grav['pages']->find($childRoute);
            $allPages = $this->collectAndFilterPages($this->grav['pages']->instances(), $filters);
            $items = $allPages;
        }

        // Check parent's collection ordering (e.g. blog ordered by date desc)
        $collectionSort = null;
        $collectionDir = 'asc';
        if ($parent) {
            $header = $parent->header();

            // Use ->get() for nested dot-notation access (works for both stdClass and Header objects)
            $getVal = function (string $key, $default = null) use ($header) {
                if (method_exists($header, 'get')) {
                    return $header->get($key, $default);
                }
                // Fallback for stdClass: walk dot-path
                $parts = explode('.', $key);
                $current = $header;
                foreach ($parts as $part) {
                    if (is_object($current) && isset($current->$part)) {
                        $current = $current->$part;
                    } elseif (is_array($current) && isset($current[$part])) {
                        $current = $current[$part];
                    } else {
                        return $default;
                    }
                }
                return $current;
            };

            $displayOrder = $getVal('admin.children_display_order', 'collection');

            if ($displayOrder === 'collection') {
                $collectionSort = $getVal('content.order.by');
                $collectionDir = $getVal('content.order.dir', 'asc');
            }
        }

        if ($collectionSort) {
            // Use collection ordering from parent header
            $sortField = match ($collectionSort) {
                'title' => 'title',
                'date' => 'date',
                'modified', 'timestamp' => 'modified',
                'slug', 'basename' => 'slug',
                default => 'order',
            };
            $items = $this->sortPages($items, $sortField, $collectionDir);
        } else {
            // Filesystem order: ordered pages first (ascending), then unordered (alpha by slug)
            $ordered = [];
            $unordered = [];
            foreach ($items as $page) {
                // Flex pages return false for unordered folders and an int (incl. 0 for "00.")
                // for ordered ones — so test for false explicitly, not truthiness.
                if ($page->order() !== false) {
                    $ordered[] = $page;
                } else {
                    $unordered[] = $page;
                }
            }
            usort($ordered, function ($a, $b) {
                return (int) $a->order() <=> (int) $b->order();
            });
            usort($unordered, function ($a, $b) {
                return strcasecmp($a->slug() ?? '', $b->slug() ?? '');
            });
            $items = array_merge($ordered, $unordered);
        }

        $total = count($items);
        $locatedAt = $this->applyLocate($items, $pagination, $request->getQueryParams()['locate'] ?? null);
        $slice = array_slice($items, $pagination['offset'], $pagination['limit']);

        $includeTranslations = filter_var(
            $request->getQueryParams()['translations'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $data = $this->attachPageCapabilities($request, $slice, $this->serializer->serializeCollection($slice, [
            'include_content' => false,
            'render_content' => false,
            'include_children' => false,
            'include_media' => false,
            'include_translations' => $includeTranslations,
        ]));

        return ApiResponse::paginated(
            data: $data,
            total: $total,
            page: $pagination['page'],
            perPage: $pagination['per_page'],
            baseUrl: $this->getApiBaseUrl() . '/pages',
            locatedAtIndex: $locatedAt,
        );
    }

    /**
     * If `$locateRoute` is set, find that page's index in the (already-sorted)
     * $items list and rewrite $pagination to point at the chunk containing it.
     * Returns the absolute index of the located page, or null if not found /
     * not requested. Locate takes precedence over any explicit `page` param —
     * the contract is "give me the chunk holding this route".
     *
     * @param list<PageInterface> $items
     * @param array{page:int,per_page:int,offset:int,limit:int} $pagination
     */
    private function applyLocate(array $items, array &$pagination, ?string $locateRoute): ?int
    {
        if ($locateRoute === null || $locateRoute === '') {
            return null;
        }
        $needle = '/' . trim($locateRoute, '/');
        foreach ($items as $idx => $page) {
            if (!$page instanceof PageInterface) {
                continue;
            }
            if ($page->route() === $needle || $page->rawRoute() === $needle) {
                $perPage = $pagination['per_page'];
                $newPage = $perPage > 0 ? ((int) floor($idx / $perPage)) + 1 : 1;
                $pagination['page'] = $newPage;
                $pagination['offset'] = ($newPage - 1) * $perPage;
                return $idx;
            }
        }
        return null;
    }

    /**
     * Sort pages by the given field and direction.
     *
     * @param list<PageInterface> $pages
     * @return list<PageInterface>
     */
    private function sortPages(array $pages, string $field, string $order): array
    {
        usort($pages, function (PageInterface $a, PageInterface $b) use ($field, $order): int {
            $result = match ($field) {
                'date' => ($a->date() ?? 0) <=> ($b->date() ?? 0),
                'modified' => ($a->modified() ?? 0) <=> ($b->modified() ?? 0),
                'title' => strcasecmp($a->title() ?? '', $b->title() ?? ''),
                'slug' => strcmp($a->slug() ?? '', $b->slug() ?? ''),
                'order' => ($a->order() ?? PHP_INT_MAX) <=> ($b->order() ?? PHP_INT_MAX),
                default => 0,
            };

            return $order === 'desc' ? -$result : $result;
        });

        return $pages;
    }

    /**
     * Batch helper: set published state on a page.
     *
     * Fires the same before/after pair as PATCH /pages/{route}. Bulk actions
     * used to run silently, so anything tracking per-page state — a search
     * index, a redirect map, and the plugin's own audit trail and webhook
     * dispatcher, which both subscribe to these events — simply missed them
     * (#23). The `method` hint lets a listener tell a bulk edit from a
     * single-page one without changing the payload shape.
     */
    private function batchPublish(PageInterface $page, string $route, bool $published): void
    {
        $header = $this->headerToArray($page->header());
        $header['published'] = $published;

        $data = ['header' => $header];
        $this->fireEvent('onApiBeforePageUpdate', ['page' => $page, 'data' => &$data, 'method' => 'batch']);

        $page->header((object) ($data['header'] ?? $header));

        $this->fireAdminEvent('onAdminSave', ['object' => &$page, 'page' => &$page]);
        $page->save();

        $this->fireAdminEvent('onAdminAfterSave', ['object' => $page, 'page' => $page]);
        $this->fireEvent('onApiPageUpdated', ['page' => $page, 'route' => $route, 'method' => 'batch']);
    }

    /**
     * Batch helper: delete a page.
     *
     * Event parity with DELETE /pages/{route} (#23). This is the one that hurt
     * most: the page was gone and no delete hook had ever run, so listeners
     * never got to clean up after it and nothing was written to the audit log.
     */
    private function batchDelete(PageInterface $page, string $route): void
    {
        $this->fireEvent('onApiBeforePageDelete', ['page' => $page, 'method' => 'batch']);

        Folder::delete($page->path());

        $this->fireAdminEvent('onAdminAfterDelete', ['object' => $page, 'page' => $page]);
        $this->fireEvent('onApiPageDeleted', ['route' => $route, 'method' => 'batch']);
    }

    /**
     * Batch helper: copy a page.
     */
    /**
     * Reject any value that is not a single path segment. A slug/suffix becomes a
     * page-folder name, never a path: a separator, parent-traversal or null byte
     * lets it escape user/pages once concatenated into a filesystem path (e.g.
     * Folder::copy()'s recursive mkdir resolves `..`). Shared by move() and
     * batchCopy(). (GHSA-qjq4-jp55-4mx2, GHSA-g6j3-8jv9-ch5f)
     *
     * @param mixed $value
     * @param string $field
     * @return string
     */
    private function assertSinglePathSegment(mixed $value, string $field): string
    {
        if (!is_string($value)
            || $value === ''
            || preg_match('#[/\\\\]#', $value)
            || str_contains($value, '..')
            || str_contains($value, "\0")) {
            throw new ValidationException("Invalid {$field}: must be a single path segment.");
        }

        return $value;
    }

    /**
     * Batch helper: copy a page. Returns the destination route so batch() can
     * fire one created-event per copy after a single index rebuild — resolving
     * each new page inside this loop would mean a full filesystem walk per
     * item (#23).
     */
    private function batchCopy(PageInterface $page, array $options): string
    {
        $destParent = $options['destination'] ?? self::structuralParentRoute($page);
        $suffix = $options['suffix'] ?? '-copy';
        // The suffix becomes part of the destination folder name; reject any
        // separator or traversal before it reaches Folder::copy() (whose
        // recursive mkdir resolves `..`). (GHSA-g6j3-8jv9-ch5f)
        $this->assertSinglePathSegment($suffix, 'suffix');
        $destSlug = $page->slug() . $suffix;

        if ($destParent === '/') {
            $destParentPath = $this->grav['locator']->findResource('page://', true);
        } else {
            $parent = $this->grav['pages']->find($destParent);
            if (!$parent) {
                throw new ValidationException("Destination parent not found: {$destParent}");
            }
            $destParentPath = $parent->path();
        }

        $destPath = $destParentPath . '/' . $destSlug;
        if (is_dir($destPath)) {
            throw new ValidationException("A page already exists at the copy destination for: {$page->route()}");
        }

        Folder::copy($page->path(), $destPath);
        $this->rewriteCopiedSlug($destPath, $destSlug, $page->extension());

        return ($destParent === '/' ? '' : rtrim($destParent, '/')) . '/' . $destSlug;
    }

    /**
     * Point a freshly copied page's `slug:` at its own folder.
     *
     * Folder::copy() reproduces the source byte for byte, so an explicit `slug:`
     * in the frontmatter travels with it and the copy claims the source's route:
     * slug() prefers the header value over the folder name, and route() is just
     * the parent route plus slug(). Grav does not reject that — Pages::buildRoutes()
     * logs "Route already exists" and lets whichever page is indexed last win, so
     * the listing shows two pages on one route, admin-next's keyed page tree
     * aborts on the duplicate, and the title bump the admin sends straight after a
     * copy can resolve to the ORIGINAL page and overwrite it. Admin-classic has
     * always rewritten the header slug when duplicating a page
     * (AdminController::taskCopy()); this gives the API the same behaviour.
     * (#25, getgrav/grav-plugin-admin2#154)
     *
     * Rewritten in place, before the index is rebuilt, so the copy already sits on
     * its own route by the time we resolve and serialize it. Doing it afterwards
     * would not be enough: Page::route() is memoized during buildRoutes(), so the
     * response would still echo the source's route.
     *
     * Only a page that already declared a slug gets one written back, so we never
     * add frontmatter the source did not have. Every language variant in the folder
     * is rewritten, because each carries its own frontmatter. Child folders are
     * left alone: their slugs are relative to this page, so they become unique
     * again as soon as the parent's does.
     *
     * @param string $destPath  Filesystem path of the copied page folder.
     * @param string $destSlug  Slug the copy should answer to.
     * @param string $extension Page file extension, including the leading dot.
     */
    private function rewriteCopiedSlug(string $destPath, string $destSlug, string $extension): void
    {
        $extension = '.' . ltrim($extension, '.');

        // scandir rather than glob: a page folder name may legitimately contain
        // `[` or `*`, which glob would read as a pattern.
        foreach (scandir($destPath) ?: [] as $filename) {
            if (!str_ends_with($filename, $extension)) {
                continue;
            }

            $file = MarkdownFile::instance($destPath . '/' . $filename);

            try {
                $header = $file->header();
                if (!is_array($header) || !array_key_exists('slug', $header)) {
                    continue;
                }

                $header['slug'] = $destSlug;
                $file->header($header);
                $file->save();
            } finally {
                // AbstractFile::instance() keeps a static instance cache, and the
                // index rebuild that follows must not read a stale one.
                $file->free();
            }
        }
    }

    /**
     * Clear the pages cache after a mutation.
     */
    private function clearPagesCache(): void
    {
        $this->grav['cache']->clearCache('standard');
    }

    /**
     * Resolve `order: "auto"` for a new page. Returns highest existing numeric
     * prefix among direct children + 1, or null when no sibling carries a
     * numeric prefix (so the new page stays unprefixed).
     */
    private function nextOrderInParent(string $parentPath): ?int
    {
        if (!is_dir($parentPath)) {
            return null;
        }

        $highest = 0;
        $hasNumeric = false;
        $dh = @opendir($parentPath);
        if ($dh === false) {
            return null;
        }
        try {
            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                [$o] = PageOrdering::parse($entry);
                if ($o !== null) {
                    $hasNumeric = true;
                    if ($o > $highest) {
                        $highest = $o;
                    }
                }
            }
        } finally {
            closedir($dh);
        }

        return $hasNumeric ? $highest + 1 : null;
    }

    /**
     * Widest existing order-prefix digit width across direct child folders of
     * $parentPath. Returns null when no children carry a numeric prefix, so
     * callers fall back to PageOrdering's configured default.
     *
     * Single readdir; no Page object instantiation. Safe for hot paths.
     */
    private function siblingDigits(string $parentPath): ?int
    {
        if (!is_dir($parentPath)) {
            return null;
        }

        $max = 0;
        $dh = @opendir($parentPath);
        if ($dh === false) {
            return null;
        }
        try {
            while (($entry = readdir($dh)) !== false) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                $w = PageOrdering::digitsFromFolder($entry);
                if ($w !== null && $w > $max) {
                    $max = $w;
                }
            }
        } finally {
            closedir($dh);
        }

        return $max ?: null;
    }

    /**
     * Make a language active for page lookups, and optionally rebuild the pages
     * index against it.
     *
     * Every language switch must go through here. `setActive()` on its own is
     * not enough: Grav memoizes the language→file-extension priority list the
     * first time `Pages::recurse()` asks for it, under a key that does not
     * include the language ("<ext>-default-0" in
     * `Language::getFallbackPageExtensions()`). So a request that activates a
     * second language gets a fresh filesystem walk resolved with the FIRST
     * language's priorities, and every page binds to the wrong translation
     * file — the target of a sync resolves to the source's file, so the write
     * lands there and the translation is never touched (#24), and a compare
     * returns the source content on both sides. Core spells out the required
     * sequence in `Language::resetFallbackPageExtensions()`'s own docblock:
     * setActive → reset → rebuild.
     *
     * This also matters beyond the request: the index built with the stale
     * priorities is cached under the legitimate per-language cache id, so a
     * poisoned build can leak into ordinary front-end requests for that
     * language until the pages hash changes.
     */
    private function switchLanguage(string $lang, bool $rebuild = true): void
    {
        /** @var Language $language */
        $language = $this->grav['language'];

        $language->setActive($lang);
        $language->resetFallbackPageExtensions();

        if ($rebuild) {
            $this->enablePages(true);
        }
    }

    /**
     * Apply language from ?lang= query parameter or an explicit language code.
     * Returns the previous active language so it can be restored.
     */
    private function applyLanguage(ServerRequestInterface $request, ?string $explicitLang = null): string|false
    {
        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;

        $lang = $explicitLang ?? ($request->getQueryParams()['lang'] ?? null);

        if ($lang !== null && $language->enabled()) {
            $this->validateLanguageCode($lang);

            $changed = $language->getActive() !== $lang;

            // Grav builds (and caches) the pages index for whichever language
            // is active at init time; enablePages()/init() are then no-ops. If
            // the index was already built for another language — or gets built
            // later at the default — find()/save() resolve to the wrong
            // translation file, so a PATCH/DELETE silently clobbers the default
            // language and a GET returns the wrong content. Force a rebuild
            // against the now-active language so route lookups target the
            // requested translation. See getgrav/grav-plugin-api#6.
            $this->switchLanguage($lang, $changed);
        }

        return $previousLang;
    }

    /**
     * Restore the previously active language.
     *
     * The pages index is deliberately left as the endpoint built it (rebuilding
     * it again would cost a full filesystem walk for nothing), but the
     * extension-priority memo is dropped so anything later in the request
     * resolves page files against the language that is actually active again.
     */
    private function restoreLanguage(string|false $previousLang): void
    {
        if ($previousLang === false) {
            // No language was active before — nothing to restore
            return;
        }

        $this->switchLanguage($previousLang, false);
    }

    /**
     * Validate that a language code is configured in the site.
     */
    private function validateLanguageCode(string $lang): void
    {
        /** @var Language $language */
        $language = $this->grav['language'];

        if (!$language->enabled()) {
            throw new ValidationException('Multi-language is not enabled on this site.');
        }

        if (!$language->validate($lang)) {
            $supported = implode(', ', $language->getLanguages());
            throw new ValidationException("Invalid language code '{$lang}'. Supported languages: {$supported}");
        }
    }

    /**
     * Check if multi-language is enabled.
     */
    private function isMultiLangEnabled(): bool
    {
        /** @var Language $language */
        $language = $this->grav['language'];
        return $language->enabled();
    }

    /**
     * Build the page filename with optional language extension.
     * e.g., "default.md" or "default.fr.md".
     *
     * Templates may be namespaced with a directory prefix (e.g. modular
     * templates come back from Grav core as `modular/hero`). Only the basename
     * is used for the on-disk filename — the directory prefix is purely for
     * template lookup. Without this, a modular sub-page would write a file at
     * `<folder>/modular/hero.md` rather than the expected `<folder>/hero.md`.
     */
    private function buildPageFilename(string $template, ?string $lang): string
    {
        $base = basename($template);

        if ($lang === null || !$this->isMultiLangEnabled()) {
            return $base . '.md';
        }

        /** @var Language $language */
        $language = $this->grav['language'];

        // For the default language, use plain .md (Grav convention)
        // unless include_default_lang is configured
        $default = $language->getDefault();
        $includeDefault = $this->grav['config']->get('system.languages.include_default_lang_file_extension', true);

        if ($lang === $default && !$includeDefault) {
            return $base . '.md';
        }

        return $base . '.' . $lang . '.md';
    }

    /**
     * Delete only a specific language file for a page, preserving other translations.
     */
    private function deleteLanguageFile(PageInterface $page, string $lang): void
    {
        $this->validateLanguageCode($lang);

        $translated = $page->translatedLanguages();
        if (!isset($translated[$lang])) {
            throw new NotFoundException("No translation found for language '{$lang}' at route: {$page->route()}");
        }

        // If this is the only translation, delete the entire page directory
        if (count($translated) <= 1) {
            Folder::delete($page->path());
            return;
        }

        // Find and delete the specific language file
        $template = $page->template();
        $pagePath = $page->path();

        /** @var Language $language */
        $language = $this->grav['language'];
        $default = $language->getDefault();

        // Try language-specific filename first, then plain .md for default lang
        $candidates = [
            $pagePath . '/' . $template . '.' . $lang . '.md',
        ];

        if ($lang === $default) {
            $candidates[] = $pagePath . '/' . $template . '.md';
        }

        foreach ($candidates as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
                return;
            }
        }

        throw new NotFoundException("Could not locate the language file for '{$lang}' at route: {$page->route()}");
    }

    /**
     * Convert a page header into a plain array.
     *
     * Flex pages (Grav's default since 1.7) return a Header/Data object from
     * header(), not a stdClass. Casting that object with (array) leaks its
     * protected properties as NUL-prefixed keys ("\0*\0items",
     * "\0*\0nestedSeparator"), which then get merged back in and persisted into
     * the frontmatter — corrupting the file a little more on every save (see
     * grav-plugin-admin2#31, triggered by Expert-mode frontmatter edits).
     *
     * Going through JSON invokes the object's jsonSerialize() and yields the
     * clean field keys, matching how PageSerializer reads headers. Legacy pages
     * (stdClass header) and already-plain arrays round-trip cleanly too.
     *
     * @param object|array|null $header
     * @return array
     */
    private function headerToArray($header): array
    {
        if ($header === null) {
            return [];
        }
        if (is_array($header)) {
            return $header;
        }
        return json_decode(json_encode($header), true) ?: [];
    }

    /**
     * Validate the submitted page fields against the page blueprint.
     *
     * Page blueprints name their fields `header.*` (plus `content`, `slug`,
     * `folder`), so we re-key the incoming body into that shape and let
     * validateChangedFields() flatten + check only what was submitted. A flat
     * `title` in the body maps to `header.title`.
     *
     * @param object $page  The page being saved (legacy Page or Flex PageObject).
     * @param array  $body  The request body / built create payload.
     */
    private function validatePageChanges(object $page, array $body): void
    {
        if (!method_exists($page, 'getBlueprint')) {
            return;
        }

        $changes = [];
        if (array_key_exists('header', $body) && is_array($body['header'])) {
            $changes['header'] = $body['header'];
        }
        if (array_key_exists('title', $body)) {
            $changes['header']['title'] = $body['title'];
        }
        if (array_key_exists('content', $body)) {
            $changes['content'] = $body['content'];
        }

        $this->validateChangedFields($changes, $page->getBlueprint());

        // Render-time XSS backstop for assembled content Twig. The core save path
        // (PageObject::onBeforeSave) enforces this unconditionally; surfacing it
        // here as a field-level error lets admin-next report it cleanly instead of
        // a bare save exception. (GHSA-2c4f-86xc-cr74)
        if (array_key_exists('content', $changes) && $page instanceof PageInterface) {
            $found = Security::detectXssInEditorContent((string) $changes['content'], $page);
            if ($found !== null) {
                throw new ValidationException(
                    'The submitted data did not pass blueprint validation.',
                    [[
                        'field' => 'content',
                        'message' => sprintf('Page content resolves to disallowed markup (%s) after Twig processing.', $found),
                    ]]
                );
            }
        }
    }

    /**
     * Recursively strip null values from an array.
     * Used to remove header fields that were toggled off (sent as null).
     */
    private function stripNullValues(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->stripNullValues($value);
                // Remove empty arrays left after stripping
                if (empty($data[$key])) {
                    unset($data[$key]);
                }
            }
        }
        return $data;
    }

    /**
     * Enforce the `security.twig_content.*` gate when a request touches a page
     * with `process: { twig: true }` (either incoming, existing, or both).
     *
     * Three rejection cases:
     *   - REASON_DISABLED       — site-wide gate is off; nobody can save twig:true.
     *   - REASON_PAGE_FORBIDDEN — page already has twig:true and the user can't edit it.
     *   - REASON_FORBIDDEN      — user is trying to enable twig but lacks permission.
     *
     * The `admin.pages_twig` permission is deliberately named outside the
     * `admin.pages` hierarchy so granting `admin.pages` does NOT implicitly
     * grant twig-toggle (the Flex ACL walks parent prefixes).
     */
    private function guardTwigContent(ServerRequestInterface $request, ?PageInterface $existingPage, array $incomingHeader): void
    {
        $user = $this->getUser($request);
        $existingTwig = false;
        if ($existingPage !== null) {
            $existingHeader = $this->headerToArray($existingPage->header());
            $existingTwig = (bool) (($existingHeader['process']['twig'] ?? false));
        }

        $incomingTwig = null;
        if (array_key_exists('process', $incomingHeader) && is_array($incomingHeader['process'])
            && array_key_exists('twig', $incomingHeader['process'])) {
            $incomingTwig = (bool) $incomingHeader['process']['twig'];
        }

        $touchesTwig = $existingTwig || $incomingTwig === true;
        if (!$touchesTwig) {
            return;
        }

        $config = $this->grav['config'];

        if ((bool) $config->get('security.twig_content.process_enabled', false) === false) {
            throw new TwigContentForbiddenException(TwigContentForbiddenException::REASON_DISABLED);
        }

        $editorEnabled = (bool) $config->get('security.twig_content.editor_enabled', false);
        if ($editorEnabled) {
            return;
        }

        // The scope cap runs here too: a key scoped to api.pages.write on a super
        // account must also carry admin.pages_twig in its scopes, otherwise the
        // bare isSuperAdmin() branch would re-grant a capability the key was scoped
        // out of and let it enable Twig-in-content (GHSA-96xv-p87j-58mx).
        // @scope-cap-exempt: the cap is the scopeAllows() conjunct on this same
        // condition, so the super branch cannot be reached uncapped.
        if ($this->scopeAllows($request, 'admin.pages_twig')
            && ($this->isSuperAdmin($user) || $this->hasPermission($user, 'admin.pages_twig'))) {
            return;
        }

        // Distinguish between "you can't edit this twig page" and "you can't
        // enable twig" so the UI can render the right toast.
        $reason = $existingTwig
            ? TwigContentForbiddenException::REASON_PAGE_FORBIDDEN
            : TwigContentForbiddenException::REASON_FORBIDDEN;
        throw new TwigContentForbiddenException($reason);
    }
}
