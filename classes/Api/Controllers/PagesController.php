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
use Grav\Framework\Flex\FlexDirectory;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\TwigContentForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\FlexBackend;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

        // Apply filters using flex methods where available
        if (isset($filters['published'])) {
            $bool = filter_var($filters['published'], FILTER_VALIDATE_BOOLEAN);
            if (method_exists($collection, 'withPublished')) {
                $collection = $collection->withPublished($bool);
            }
        }
        if (isset($filters['visible'])) {
            $bool = filter_var($filters['visible'], FILTER_VALIDATE_BOOLEAN);
            if (method_exists($collection, 'withVisible')) {
                $collection = $collection->withVisible($bool);
            }
        }
        if (isset($filters['routable'])) {
            $bool = filter_var($filters['routable'], FILTER_VALIDATE_BOOLEAN);
            if (method_exists($collection, 'withRoutable')) {
                $collection = $collection->withRoutable($bool);
            }
        }

        // Template, parent, children_of, root — filter manually on the collection
        if (isset($filters['template']) || isset($filters['parent']) || isset($filters['children_of']) || isset($filters['root'])) {
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

        $data = $this->serializer->serializeCollection($slice, $listOptions);

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

        $data = $this->serializer->serializeCollection($slice, $listOptions);

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
        $this->requirePermission($request, self::PERMISSION_READ);
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route);

            // If the page already has process.twig:true, the same gate that
            // governs writes also governs reading the full record. Returning
            // the editor view to a user who can't save it is misleading; let
            // Admin Next show the toast on the show() failure instead.
            $this->guardTwigContent($page, [], $this->getUser($request));

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

            return $this->respondWithEtag($data);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * POST /pages - Create a new page.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);

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
            $parentRoute = dirname($route);
            $slug = basename($route);

            // Modular sub-page convention: folder name starts with `_`.
            if ($kind === 'module' && !str_starts_with($slug, '_')) {
                $slug = '_' . $slug;
                $route = ($parentRoute === '/' ? '' : $parentRoute) . '/' . $slug;
            }

            if ($parentRoute !== '/') {
                $parent = $this->grav['pages']->find($parentRoute);
                if (!$parent) {
                    throw new ValidationException("Parent page not found at route: {$parentRoute}");
                }
                $parentPath = $parent->path();
            } else {
                $parentPath = $this->grav['locator']->findResource('page://', true);
            }

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
            $this->guardTwigContent(null, $header, $this->getUser($request));

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
     * @return array<string, mixed>
     */
    private function blueprintHeaderDefaults(string $template): array
    {
        try {
            $blueprint = $this->grav['pages']->blueprints($template);
            $defaults = $blueprint->getDefaults();
        } catch (\Throwable) {
            return [];
        }

        return is_array($defaults['header'] ?? null) ? $defaults['header'] : [];
    }

    /**
     * PATCH /pages/{route} - Partial update of a page.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route);

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
            $this->guardTwigContent($page, (array) ($body['header'] ?? []), $this->getUser($request));

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
                $existing = $this->headerToArray($page->header());
                $merged = $this->mergePatch($existing, $body['header']);
                // Strip null values — toggleable fields send null to signal removal
                $merged = $this->stripNullValues($merged);
                $page->header((object) $merged);
                // Sync properties that legacy Page caches separately from the
                // header dict (otherwise they stay stale until reload).
                if (array_key_exists('published', $body['header'])) {
                    $page->published((bool) $body['header']['published']);
                }
                if (array_key_exists('visible', $body['header'])) {
                    $page->visible((bool) $body['header']['visible']);
                }
            }

            // Template change requires renaming the page file (e.g. default.md → post.md)
            $templateChanged = false;
            $oldFilePath = null;
            if (array_key_exists('template', $body) && $body['template'] !== $page->template()) {
                $oldFilePath = $page->path() . '/' . $page->template() . ($page->language() ? '.' . $page->language() : '') . '.md';
                $page->template($body['template']);
                $page->name($body['template'] . ($page->language() ? '.' . $page->language() : '') . '.md');
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

            return $this->respondWithEtag($data, 200, ['pages:update:/' . $route, 'pages:list']);
        } finally {
            $this->restoreLanguage($previousLang);
        }
    }

    /**
     * DELETE /pages/{route} - Delete a page.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $previousLang = $this->applyLanguage($request);

        try {
            $this->enablePages();

            $route = $this->getRouteParam($request, 'route');
            $page = $this->findPageOrFail('/' . $route);

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
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['parent']);

        $newParentRoute = '/' . trim($body['parent'], '/');
        $newSlug = ltrim($body['slug'] ?? $page->slug(), '.');
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
            $newParentPath = $this->grav['locator']->findResource('page://', true);
        } else {
            $newParent = $this->grav['pages']->find($newParentRoute);
            if (!$newParent) {
                throw new ValidationException("Destination parent not found at route: {$newParentRoute}");
            }
            $newParentPath = $newParent->path();
        }

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

        return $this->respondWithEtag($data, 200, $moveTags);
    }

    /**
     * POST /pages/{route}/copy - Copy a page to a new location.
     */
    public function copy(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['route']);

        $destRoute = '/' . trim($body['route'], '/');
        $destSlug = basename($destRoute);
        $destParentRoute = dirname($destRoute);

        // Resolve destination parent path
        if ($destParentRoute === '/') {
            $destParentPath = $this->grav['locator']->findResource('page://', true);
        } else {
            $destParent = $this->grav['pages']->find($destParentRoute);
            if (!$destParent) {
                throw new ValidationException("Destination parent not found at route: {$destParentRoute}");
            }
            $destParentPath = $destParent->path();
        }

        $destPath = $destParentPath . '/' . $destSlug;

        if (is_dir($destPath)) {
            throw new ValidationException("A page already exists at route: {$destRoute}");
        }

        $sourcePath = $page->path();
        Folder::copy($sourcePath, $destPath);
        $this->clearPagesCache();

        // Re-init and find the copied page
        $this->enablePages(true);
        $copiedPage = $this->grav['pages']->find($destRoute);

        $copyTags = ['pages:create:' . $destRoute, 'pages:list'];

        if (!$copiedPage) {
            return ApiResponse::created(
                ['route' => $destRoute, 'slug' => $destSlug],
                $this->getApiBaseUrl() . '/pages' . $destRoute,
                $this->invalidationHeaders($copyTags),
            );
        }

        $data = $this->serializer->serialize($copiedPage);
        $location = $this->getApiBaseUrl() . '/pages' . $destRoute;

        return ApiResponse::created($data, $location, $this->invalidationHeaders($copyTags));
    }

    /**
     * GET /pages/{route}/languages - List available and missing translations for a page.
     */
    public function languages(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

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
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

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

        // Allow plugins to modify the page before save
        $this->fireAdminEvent('onAdminSave', ['object' => &$translatedPage, 'page' => &$translatedPage]);

        $translatedPage->save();

        $this->clearPagesCache();

        // Re-init and fetch the translated page
        /** @var Language $language */
        $language = $this->grav['language'];
        $previousLang = $language->getActive() ?? false;
        $language->setActive($lang);

        try {
            $this->enablePages(true);
            $newPage = $this->grav['pages']->find('/' . $route);

            $this->fireAdminEvent('onAdminAfterSave', ['object' => $newPage ?? $translatedPage, 'page' => $newPage ?? $translatedPage]);
            $this->fireEvent('onApiPageTranslated', [
                'page' => $newPage ?? $translatedPage,
                'route' => '/' . $route,
                'lang' => $lang,
            ]);

            $data = $this->serializer->serialize($newPage ?? $translatedPage);
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
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

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
        $language->setActive($lang);

        try {
            $this->enablePages(true);
            $newPage = $this->grav['pages']->find('/' . $route);

            $this->fireEvent('onApiPageLanguageAdopted', [
                'page' => $newPage ?? $page,
                'route' => '/' . $route,
                'lang' => $lang,
            ]);

            $data = $this->serializer->serialize($newPage ?? $page);

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
        $this->requirePermission($request, self::PERMISSION_WRITE);

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
            $language->setActive($sourceLang);
            $this->enablePages(true);
            $sourcePage = $this->grav['pages']->find('/' . $route);

            if (!$sourcePage) {
                throw new NotFoundException("Page not found at route '/{$route}' for source language '{$sourceLang}'.");
            }

            $sourceContent = $sourcePage->rawMarkdown();
            $sourceHeader = $this->headerToArray($sourcePage->header());

            // Load the target page
            $language->setActive($targetLang);
            $this->enablePages(true);
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

            // Overwrite the target with source data
            $targetPage->header((object) $sourceHeader);
            $targetPage->rawMarkdown($sourceContent);

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
            $language->setActive($sourceLang);
            $this->enablePages(true);
            $sourcePage = $this->grav['pages']->find('/' . $route);

            $sourceData = null;
            if ($sourcePage) {
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

            // Load target page
            $language->setActive($targetLang);
            $this->enablePages(true);
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
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $parent = $this->findPageOrFail('/' . $route);

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

        $results = [];

        foreach ($pages as $route => $page) {
            try {
                match ($operation) {
                    'publish' => $this->batchPublish($page, true),
                    'unpublish' => $this->batchPublish($page, false),
                    'delete' => $this->batchDelete($page),
                    'copy' => $this->batchCopy($page, $options),
                };
                $results[] = ['route' => $route, 'status' => 'success'];
            } catch (\Throwable $e) {
                $results[] = ['route' => $route, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        $this->clearPagesCache();

        // Build per-route invalidations so listeners on specific pages react too.
        $tags = ['pages:list'];
        foreach ($results as $r) {
            if ($r['status'] !== 'success') continue;
            $tags[] = match ($operation) {
                'delete' => 'pages:delete:' . $r['route'],
                'copy' => 'pages:create:' . $r['route'],
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

            $currentParentRoute = dirname($page->route()) ?: '/';
            if ($currentParentRoute === '.') {
                $currentParentRoute = '/';
            }
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
                $check = dirname($check);
                if ($check === '.') {
                    $check = '/';
                    break;
                }
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

        // --- Phase 4: Build response with all affected pages ---
        $affectedData = [];
        foreach (array_keys($affectedParentRoutes) as $parentRoute) {
            $parent = $parentRoute === '/'
                ? $this->grav['pages']->find('/')
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

        // Simplify: return just taxonomy type => [values] without internal file paths
        $taxonomy = [];
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
     * Find a page by route or throw NotFoundException.
     */
    private function findPageOrFail(string $route): PageInterface
    {
        $page = $this->grav['pages']->find($route);

        if (!$page) {
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
                if ($this->isDirectChildOf($page, $filters['children_of'])) {
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

        $data = $this->serializer->serializeCollection($slice, [
            'include_content' => false,
            'render_content' => false,
            'include_children' => false,
            'include_media' => false,
            'include_translations' => $includeTranslations,
        ]);

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
     */
    private function batchPublish(PageInterface $page, bool $published): void
    {
        $header = $this->headerToArray($page->header());
        $header['published'] = $published;
        $page->header((object) $header);
        $page->save();
    }

    /**
     * Batch helper: delete a page.
     */
    private function batchDelete(PageInterface $page): void
    {
        Folder::delete($page->path());
    }

    /**
     * Batch helper: copy a page.
     */
    private function batchCopy(PageInterface $page, array $options): void
    {
        $destParent = $options['destination'] ?? dirname($page->route());
        $suffix = $options['suffix'] ?? '-copy';
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
            $language->setActive($lang);

            // Grav builds (and caches) the pages index for whichever language
            // is active at init time; enablePages()/init() are then no-ops. If
            // the index was already built for another language — or gets built
            // later at the default — find()/save() resolve to the wrong
            // translation file, so a PATCH/DELETE silently clobbers the default
            // language and a GET returns the wrong content. Force a rebuild
            // against the now-active language so route lookups target the
            // requested translation. See getgrav/grav-plugin-api#6.
            if ($changed) {
                $pages = $this->grav['pages'];
                $pages->enablePages();
                $pages->reset();
            }
        }

        return $previousLang;
    }

    /**
     * Restore the previously active language.
     */
    private function restoreLanguage(string|false $previousLang): void
    {
        if ($previousLang === false) {
            // No language was active before — nothing to restore
            return;
        }

        /** @var Language $language */
        $language = $this->grav['language'];
        $language->setActive($previousLang);
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
    private function guardTwigContent(?PageInterface $existingPage, array $incomingHeader, UserInterface $user): void
    {
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

        if ($this->isSuperAdmin($user) || $this->hasPermission($user, 'admin.pages_twig')) {
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
