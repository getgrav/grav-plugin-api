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
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PagesController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.pages.read';
    private const PERMISSION_WRITE = 'api.pages.write';

    private const ALLOWED_FILTERS = ['published', 'template', 'routable', 'visible', 'parent', 'children_of', 'root'];
    private const ALLOWED_SORT_FIELDS = ['date', 'title', 'slug', 'modified', 'order'];

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
            $this->enablePages();

            $filters = $this->getFilters($request, self::ALLOWED_FILTERS);
            $sorting = $this->getSorting($request, self::ALLOWED_SORT_FIELDS);
            $pagination = $this->getPagination($request);

            // Default sort: date desc
            $sortField = $sorting['sort'] ?? 'date';
            $sortOrder = $sorting['sort'] ? $sorting['order'] : 'desc';

            $pages = $this->grav['pages'];
            $allPages = $this->collectAndFilterPages($pages->instances(), $filters);
            $allPages = $this->sortPages($allPages, $sortField, $sortOrder);

            $total = count($allPages);
            $slice = array_slice($allPages, $pagination['offset'], $pagination['limit']);

            $includeTranslations = filter_var(
                $request->getQueryParams()['translations'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            // Use lighter serialization for listing (no content, no children)
            $listOptions = [
                'include_content' => false,
                'render_content' => false,
                'include_children' => false,
                'include_media' => false,
                'include_translations' => $includeTranslations,
            ];

            $data = $this->serializer->serializeCollection($slice, $listOptions);
            $baseUrl = $this->getApiBaseUrl() . '/pages';

            return ApiResponse::paginated(
                data: $data,
                total: $total,
                page: $pagination['page'],
                perPage: $pagination['per_page'],
                baseUrl: $baseUrl,
            );
        } finally {
            $this->restoreLanguage($previousLang);
        }
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

            // Ensure parent exists
            $parentRoute = dirname($route);
            $slug = basename($route);

            if ($parentRoute !== '/') {
                $parent = $this->grav['pages']->find($parentRoute);
                if (!$parent) {
                    throw new ValidationException("Parent page not found at route: {$parentRoute}");
                }
                $parentPath = $parent->path();
            } else {
                $parentPath = $this->grav['locator']->findResource('page://', true);
            }

            // Build directory name with optional ordering prefix
            $dirName = $order !== null ? str_pad((string) $order, 2, '0', STR_PAD_LEFT) . '.' . $slug : $slug;
            $pagePath = $parentPath . '/' . $dirName;

            if (is_dir($pagePath)) {
                throw new ValidationException("A page already exists at route: {$route}");
            }

            // Build header with title
            $header = array_merge(['title' => $title], $header);

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

            // Build filename with language extension if applicable
            $filename = $this->buildPageFilename($template, $lang);

            $page = new Page();
            $page->filePath($pagePath . '/' . $filename);
            $page->header((object) $header);
            $page->rawMarkdown($content);

            // Allow plugins to modify the page before save (e.g. SEO Magic, mega-frontmatter)
            $this->fireAdminEvent('onAdminSave', ['object' => &$page, 'page' => &$page]);

            $page->save();

            $this->clearPagesCache();

            // Re-init pages and fetch the newly created page for serialization
            $this->enablePages(true);
            $newPage = $this->grav['pages']->find($route);

            $this->fireAdminEvent('onAdminAfterSave', ['object' => $newPage ?? $page, 'page' => $newPage ?? $page]);
            $this->fireEvent('onApiPageCreated', ['page' => $newPage ?? $page, 'route' => $route, 'lang' => $lang]);

            $data = $this->serializer->serialize($newPage ?? $page);
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

            // Fire before event — plugins can modify $body or throw to cancel
            $this->fireEvent('onApiBeforePageUpdate', ['page' => $page, 'data' => &$body]);

            if (array_key_exists('content', $body)) {
                $page->rawMarkdown($body['content']);
            }

            if (array_key_exists('title', $body)) {
                $header = (array) $page->header();
                $header['title'] = $body['title'];
                $page->header((object) $header);
            }

            if (array_key_exists('header', $body)) {
                $existing = (array) $page->header();
                $merged = array_replace_recursive($existing, $body['header']);
                // Strip null values — toggleable fields send null to signal removal
                $merged = $this->stripNullValues($merged);
                $page->header((object) $merged);
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
                $header = (array) $page->header();
                $header['published'] = (bool) $body['published'];
                $page->header((object) $header);
            }

            if (array_key_exists('visible', $body)) {
                $header = (array) $page->header();
                $header['visible'] = (bool) $body['visible'];
                $page->header((object) $header);
            }

            // Allow plugins to modify the page before save
            $this->fireAdminEvent('onAdminSave', ['object' => &$page, 'page' => &$page]);

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
        $newOrder = array_key_exists('order', $body) ? $body['order'] : $page->order();

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
        $header = $body['header'] ?? (array) $page->header();

        // Ensure title is set
        $header = array_merge(['title' => $title], is_array($header) ? $header : []);

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
            $sourceHeader = (array) $sourcePage->header();

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
                    'header' => (array) $sourcePage->header(),
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
                    'header' => (array) $targetPage->header(),
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

        // Rename directories with new ordering prefixes
        $tempRenames = [];
        $position = 1;

        foreach ($order as $slug) {
            $currentDir = $childMap[$slug];
            $newPrefix = str_pad((string) $position, 2, '0', STR_PAD_LEFT);
            $newDir = $newPrefix . '.' . $slug;

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

            $resolved[] = [
                'route' => $route,
                'page' => $page,
                'slug' => $page->slug(),
                'oldPath' => $page->path(),
                'currentParentRoute' => $currentParentRoute,
                'newParentRoute' => $newParentRoute,
                'newParentPath' => $newParentPath,
                'position' => $position,
            ];
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
            // Skip the root page
            if (!$page->route() || $page->route() === '/') {
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
                'root' => filter_var($value, FILTER_VALIDATE_BOOLEAN) && substr_count(trim($page->route(), '/'), '/') === 0,
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
     * Direct child = parent route + one slug segment, no deeper.
     */
    private function isDirectChildOf(PageInterface $page, string $parentValue): bool
    {
        $parentRoute = '/' . trim($parentValue, '/');
        $pageRoute = $page->route();

        if ($parentRoute === '/') {
            // Root children: routes like /home, /blog (one segment only)
            return substr_count(trim($pageRoute, '/'), '/') === 0;
        }

        // Must start with parent route + / and have exactly one more segment
        if (!str_starts_with($pageRoute, $parentRoute . '/')) {
            return false;
        }

        $remainder = substr($pageRoute, strlen($parentRoute) + 1);
        return !str_contains($remainder, '/');
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
        $header = (array) $page->header();
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
            $language->setActive($lang);
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
     * e.g., "default.md" or "default.fr.md"
     */
    private function buildPageFilename(string $template, ?string $lang): string
    {
        if ($lang === null || !$this->isMultiLangEnabled()) {
            return $template . '.md';
        }

        /** @var Language $language */
        $language = $this->grav['language'];

        // For the default language, use plain .md (Grav convention)
        // unless include_default_lang is configured
        $default = $language->getDefault();
        $includeDefault = $this->grav['config']->get('system.languages.include_default_lang_file_extension', true);

        if ($lang === $default && !$includeDefault) {
            return $template . '.md';
        }

        return $template . '.' . $lang . '.md';
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
}
