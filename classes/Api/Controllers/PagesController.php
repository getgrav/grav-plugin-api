<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Filesystem\Folder;
use Grav\Common\Grav;
use Grav\Common\Config\Config;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class PagesController extends AbstractApiController
{
    private const PERMISSION_READ = 'api.pages.read';
    private const PERMISSION_WRITE = 'api.pages.write';

    private const ALLOWED_FILTERS = ['published', 'template', 'routable', 'visible', 'parent'];
    private const ALLOWED_SORT_FIELDS = ['date', 'title', 'slug', 'modified', 'order'];

    private readonly PageSerializer $serializer;

    public function __construct(Grav $grav, Config $config)
    {
        parent::__construct($grav, $config);
        $this->serializer = new PageSerializer();
    }

    /**
     * GET /pages - List pages with filtering, sorting, and pagination.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
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

        // Use lighter serialization for listing (no content, no children)
        $listOptions = [
            'include_content' => false,
            'render_content' => false,
            'include_children' => false,
            'include_media' => false,
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
    }

    /**
     * GET /pages/{route} - Get a single page by route.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_READ);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

        $query = $request->getQueryParams();
        $options = [
            'include_content' => true,
            'render_content' => filter_var($query['render'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'include_children' => filter_var($query['children'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'children_depth' => max(1, (int) ($query['children_depth'] ?? 1)),
            'include_media' => true,
        ];

        $data = $this->serializer->serialize($page, $options);

        return $this->respondWithEtag($data);
    }

    /**
     * POST /pages - Create a new page.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $body = $this->getRequestBody($request);
        $this->requireFields($body, ['route', 'title']);

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
        $beforeEvent = $this->fireEvent('onApiBeforePageCreate', [
            'route' => $route,
            'header' => &$header,
            'content' => &$content,
            'template' => &$template,
        ]);

        $page = new Page();
        $page->filePath($pagePath . '/' . $template . '.md');
        $page->header((object) $header);
        $page->rawMarkdown($content);
        $page->save();

        $this->clearPagesCache();

        // Re-init pages and fetch the newly created page for serialization
        $this->enablePages(true);
        $newPage = $this->grav['pages']->find($route);

        $this->fireEvent('onApiPageCreated', ['page' => $newPage ?? $page, 'route' => $route]);

        $data = $this->serializer->serialize($newPage ?? $page);
        $location = $this->getApiBaseUrl() . '/pages' . $route;

        return ApiResponse::created($data, $location);
    }

    /**
     * PATCH /pages/{route} - Partial update of a page.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

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
            $merged = array_merge($existing, $body['header']);
            $page->header((object) $merged);
        }

        if (array_key_exists('template', $body)) {
            $page->template($body['template']);
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

        $page->save();
        $this->clearPagesCache();

        $this->fireEvent('onApiPageUpdated', ['page' => $page]);

        $data = $this->serializer->serialize($page);

        return $this->respondWithEtag($data);
    }

    /**
     * DELETE /pages/{route} - Delete a page.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, self::PERMISSION_WRITE);
        $this->enablePages();

        $route = $this->getRouteParam($request, 'route');
        $page = $this->findPageOrFail('/' . $route);

        $query = $request->getQueryParams();
        $includeChildren = filter_var($query['children'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if (!$includeChildren && $page->children()->count() > 0) {
            throw new ValidationException(
                'This page has children. Use ?children=true to confirm deletion of the page and all its children.'
            );
        }

        $this->fireEvent('onApiBeforePageDelete', ['page' => $page]);

        $pagePath = $page->path();
        Folder::delete($pagePath);

        $this->clearPagesCache();

        $this->fireEvent('onApiPageDeleted', ['route' => '/' . $route]);

        return ApiResponse::noContent();
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
        $newSlug = $body['slug'] ?? $page->slug();
        $newOrder = $body['order'] ?? $page->order();

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

        // Re-init and find the moved page
        $this->enablePages(true);
        $newRoute = $newParentRoute === '/' ? '/' . $newSlug : $newParentRoute . '/' . $newSlug;
        $movedPage = $this->grav['pages']->find($newRoute);

        $this->fireEvent('onApiPageMoved', [
            'page' => $movedPage,
            'old_route' => '/' . $route,
            'new_route' => $newRoute,
        ]);

        if (!$movedPage) {
            // Fallback: return minimal data if page can't be found at expected route
            return ApiResponse::create(['route' => $newRoute, 'slug' => $newSlug]);
        }

        $data = $this->serializer->serialize($movedPage);

        return $this->respondWithEtag($data);
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

        if (!$copiedPage) {
            return ApiResponse::created(
                ['route' => $destRoute, 'slug' => $destSlug],
                $this->getApiBaseUrl() . '/pages' . $destRoute,
            );
        }

        $data = $this->serializer->serialize($copiedPage);
        $location = $this->getApiBaseUrl() . '/pages' . $destRoute;

        return ApiResponse::created($data, $location);
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
                default => true,
            };

            if (!$matches) {
                return false;
            }
        }

        return true;
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
     * Clear the pages cache after a mutation.
     */
    private function clearPagesCache(): void
    {
        $this->grav['cache']->clearCache('standard');
    }
}
