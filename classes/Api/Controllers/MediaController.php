<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Yaml;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MediaController extends AbstractApiController
{
    use HandlesMediaUploads;

    /**
     * Per-folder sidecar holding a manual ordering of site media. Mirrors the
     * page-media `header.media_order` concept for folders that have no page to
     * hang the order on. Lives inside the folder it orders and is excluded from
     * media listings.
     */
    private const MEDIA_ORDER_FILE = 'media_order.yaml';

    /**
     * GET /pages/{route}/media - List all media for a page.
     */
    public function pageMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');

        $page = $this->findPageOrFail($request);
        $pagePath = $page->path();

        // Create fresh Media object to avoid stale page cache
        $media = new \Grav\Common\Page\Media($pagePath);
        $serialized = $this->getSerializer()->serializeCollection($media->all());

        return ApiResponse::create($serialized);
    }

    /**
     * POST /pages/{route}/media - Upload file(s) to a page.
     */
    public function uploadPageMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $page = $this->findPageOrFail($request);
        $pagePath = $page->path();

        if (!$pagePath || !is_dir($pagePath)) {
            throw new NotFoundException('Page directory does not exist on disk.');
        }

        $uploadedFiles = $this->flattenUploadedFiles($request->getUploadedFiles());

        if ($uploadedFiles === []) {
            throw new ValidationException('No files were uploaded.');
        }

        // Honor per-field upload settings (random_name, accept, ...) when the
        // file field forwards them; absent, this is an inert no-op.
        $settings = $this->parseUploadFieldSettings($request);

        $uploadedNames = [];
        foreach ($uploadedFiles as $file) {
            // Fire before event — plugins can throw to reject specific files
            $this->fireEvent('onApiBeforeMediaUpload', [
                'page' => $page,
                'filename' => $file->getClientFilename(),
                'type' => $file->getClientMediaType(),
                'size' => $file->getSize(),
            ]);

            $uploadedNames[] = $this->processUploadedFile($file, $pagePath, $settings);
        }

        // Create fresh Media object to pick up newly uploaded files
        $media = new \Grav\Common\Page\Media($pagePath);
        $serialized = $this->getSerializer()->serializeCollection($media->all());

        $this->fireAdminEvent('onAdminAfterAddMedia', ['object' => $page, 'page' => $page]);
        $this->fireEvent('onApiMediaUploaded', [
            'page' => $page,
            'filenames' => $uploadedNames,
        ]);

        $baseUrl = $this->getApiBaseUrl();
        $route = $this->getRouteParam($request, 'route') ?? '';
        $location = "{$baseUrl}/pages/{$route}/media";

        return ApiResponse::created(
            $serialized,
            $location,
            $this->invalidationHeaders([
                'media:update:pages/' . $route,
                'pages:update:/' . $route,
            ]),
        );
    }

    /**
     * DELETE /pages/{route}/media/{filename} - Delete a media file from a page.
     */
    public function deletePageMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $page = $this->findPageOrFail($request);
        $filename = $this->getSafeFilename($request);
        $pagePath = $page->path();

        if (!$pagePath) {
            throw new NotFoundException('Page directory does not exist on disk.');
        }

        // Collect every physical file backing this medium: the base file, its
        // retina `@Nx` variants, and any `.meta.yaml` siblings. A migrated
        // image stored only as `photo@2x.jpg` (no base `photo.jpg`) is listed
        // by Grav under the synthesized base name `photo.jpg`, which has no file
        // on disk — so a plain `file_exists(photo.jpg)` check 404'd it (admin2#68).
        // Sweeping the variants also stops a deleted base from leaving an orphan
        // `@2x` behind (which would reappear as a ghost base in the listing).
        $targets = $this->mediaFileVariants($pagePath, $filename);
        if ($targets === []) {
            throw new NotFoundException("Media file '{$filename}' not found on this page.");
        }

        $this->fireEvent('onApiBeforeMediaDelete', ['page' => $page, 'filename' => $filename]);

        foreach ($targets as $target) {
            if (is_file($target)) {
                unlink($target);
            }
        }

        // Build fresh media object for admin event compatibility
        $media = new \Grav\Common\Page\Media($pagePath);
        $this->fireAdminEvent('onAdminAfterDelMedia', [
            'object' => $page, 'page' => $page,
            'media' => $media, 'filename' => $filename,
        ]);
        $this->fireEvent('onApiMediaDeleted', ['page' => $page, 'filename' => $filename]);

        $route = $this->getRouteParam($request, 'route') ?? '';
        return ApiResponse::noContent(
            $this->invalidationHeaders([
                'media:delete:pages/' . $route . '/' . $filename,
                'media:update:pages/' . $route,
                'pages:update:/' . $route,
            ]),
        );
    }

    /**
     * Absolute paths of every file backing a medium in $dir: the base file
     * `<stem>.<ext>`, its retina variants `<stem>@<N>x.<ext>`, and the
     * `.meta.yaml` sidecar of each. Used by deletion so retina-only images
     * (no physical base) are still removed and no `@Nx` orphans are left
     * behind (admin2#68). Only files that exist are returned.
     *
     * @return list<string>
     */
    private function mediaFileVariants(string $dir, string $filename): array
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        // <stem>@2x.<ext>, <stem>@3x.<ext>, … (case-insensitive extension)
        $variantRe = '/^' . preg_quote($stem, '/') . '@\d+x\.' . preg_quote($ext, '/') . '$/i';

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($entry !== $filename && !preg_match($variantRe, $entry)) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_file($path)) {
                $files[] = $path;
            }
            $meta = $path . '.meta.yaml';
            if (is_file($meta)) {
                $files[] = $meta;
            }
        }

        return $files;
    }

    /**
     * GET /media - List site-level media with folder browsing, search, and type filter.
     */
    public function siteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');

        $mediaPath = $this->getSiteMediaPath();
        $queryParams = $request->getQueryParams();

        // Validate optional path parameter
        $relativePath = '';
        if (!empty($queryParams['path'])) {
            $relativePath = $this->validateRelativePath($queryParams['path'], $mediaPath);
        }

        $currentPath = $relativePath !== '' ? $mediaPath . '/' . $relativePath : $mediaPath;

        // Handle search mode
        if (!empty($queryParams['search'])) {
            return $this->handleMediaSearch($request, $mediaPath, $queryParams);
        }

        // Verify directory exists
        if (!is_dir($currentPath)) {
            // Return empty result for non-existent paths
            $baseUrl = $this->getApiBaseUrl() . '/media';
            return ApiResponse::paginated([], 0, 1, 20, $baseUrl, 200, [], [
                'path' => $relativePath,
                'folders' => [],
            ]);
        }

        $result = $this->scanMediaDirectoryWithFolders($currentPath, $relativePath);
        // Apply the folder's saved manual ordering before filtering/paginating.
        $result['files'] = $this->applySiteMediaOrder($result['files'], $currentPath);
        $pagination = $this->getPagination($request);

        // Apply type filter
        $typeFilter = $queryParams['type'] ?? null;
        $files = $result['files'];
        if ($typeFilter) {
            $files = array_values(array_filter($files, function (string $file) use ($currentPath, $typeFilter) {
                $mime = mime_content_type($currentPath . '/' . $file) ?: '';
                return match ($typeFilter) {
                    'image' => str_starts_with($mime, 'image/'),
                    'video' => str_starts_with($mime, 'video/'),
                    'audio' => str_starts_with($mime, 'audio/'),
                    'document' => !str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/') && !str_starts_with($mime, 'audio/'),
                    default => true,
                };
            }));
        }

        $total = count($files);
        $pagedFiles = array_slice($files, $pagination['offset'], $pagination['limit']);

        $serialized = array_map(
            fn(string $file) => $this->serializeSiteFile($currentPath, $file, $relativePath),
            $pagedFiles,
        );

        $baseUrl = $this->getApiBaseUrl() . '/media';

        return ApiResponse::paginated(
            $serialized,
            $total,
            $pagination['page'],
            $pagination['per_page'],
            $baseUrl,
            200,
            [],
            [
                'path' => $relativePath,
                'folders' => $result['folders'],
                'ordered' => is_file($currentPath . '/' . self::MEDIA_ORDER_FILE),
            ],
        );
    }

    /**
     * POST /media - Upload file(s) to the site media folder (with optional subfolder path).
     */
    public function uploadSiteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $queryParams = $request->getQueryParams();

        // Validate optional subfolder path
        $relativePath = '';
        if (!empty($queryParams['path'])) {
            $relativePath = $this->validateRelativePath($queryParams['path'], $mediaPath);
        }

        $targetDir = $relativePath !== '' ? $mediaPath . '/' . $relativePath : $mediaPath;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
            throw new ValidationException('Unable to create upload directory.');
        }

        $uploadedFiles = $this->flattenUploadedFiles($request->getUploadedFiles());

        if ($uploadedFiles === []) {
            throw new ValidationException('No files were uploaded.');
        }

        $settings = $this->parseUploadFieldSettings($request);

        $created = [];
        foreach ($uploadedFiles as $file) {
            $filename = $this->processUploadedFile($file, $targetDir, $settings);
            $created[] = $this->serializeSiteFile($targetDir, $filename, $relativePath);
        }

        $location = $this->getApiBaseUrl() . '/media';

        return ApiResponse::created(
            $created,
            $location,
            $this->invalidationHeaders(['media:update:' . ($relativePath !== '' ? $relativePath : '/'), 'media:list']),
        );
    }

    /**
     * DELETE /media/{filename} - Delete a site media file (supports subfolder paths).
     */
    public function deleteSiteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $relativePath = $this->getSafeRelativeFilePath($request, $mediaPath);
        $filePath = $mediaPath . '/' . $relativePath;

        if (!file_exists($filePath)) {
            throw new NotFoundException("Media file not found.");
        }

        unlink($filePath);

        // Also remove any metadata file
        $metaPath = $filePath . '.meta.yaml';
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }

        // Keep the folder's order sidecar coherent.
        $this->removeFromSiteMediaOrder(dirname($filePath), basename($filePath));

        $parentDir = ltrim(dirname($relativePath), '.');
        return ApiResponse::noContent(
            $this->invalidationHeaders([
                'media:delete:' . $relativePath,
                'media:update:' . ($parentDir !== '' ? $parentDir : '/'),
                'media:list',
            ]),
        );
    }

    /**
     * POST /media/folders - Create a new folder.
     */
    public function createFolder(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $body = json_decode((string) $request->getBody(), true) ?? [];

        if (empty($body['path'])) {
            throw new ValidationException('Folder path is required.');
        }

        $relativePath = $this->validateRelativePath($body['path'], $mediaPath);
        $absolutePath = $mediaPath . '/' . $relativePath;

        if (is_dir($absolutePath)) {
            throw new ValidationException('Folder already exists.');
        }

        if (!mkdir($absolutePath, 0775, true)) {
            throw new ValidationException('Unable to create folder.');
        }

        $name = basename($relativePath);
        $data = [
            'name' => $name,
            'path' => $relativePath,
            'children_count' => 0,
            'file_count' => 0,
        ];

        return ApiResponse::created(
            $data,
            $this->getApiBaseUrl() . '/media?path=' . urlencode($relativePath),
            $this->invalidationHeaders(['media:create:' . $relativePath, 'media:list']),
        );
    }

    /**
     * DELETE /media/folders/{path} - Delete an empty folder.
     */
    public function deleteFolder(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $path = $this->getRouteParam($request, 'path');

        if ($path === null || $path === '') {
            throw new ValidationException('Folder path is required.');
        }

        $relativePath = $this->validateRelativePath($path, $mediaPath);
        $absolutePath = $mediaPath . '/' . $relativePath;

        if (!is_dir($absolutePath)) {
            throw new NotFoundException('Folder not found.');
        }

        // Check if folder is empty (only . and ..)
        $isEmpty = true;
        foreach (new \DirectoryIterator($absolutePath) as $item) {
            if (!$item->isDot()) {
                $isEmpty = false;
                break;
            }
        }

        if (!$isEmpty) {
            throw new ValidationException('Folder is not empty. Delete all files first.');
        }

        if (!rmdir($absolutePath)) {
            throw new ValidationException('Unable to delete folder.');
        }

        return ApiResponse::noContent(
            $this->invalidationHeaders(['media:delete:' . $relativePath, 'media:list']),
        );
    }

    /**
     * POST /media/rename - Rename or move a media file.
     */
    public function renameFile(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $body = json_decode((string) $request->getBody(), true) ?? [];

        if (empty($body['from']) || empty($body['to'])) {
            throw new ValidationException("Both 'from' and 'to' paths are required.");
        }

        $from = $this->validateRelativePath($body['from'], $mediaPath);

        // Preserve the source file's extension and clean the requested name so
        // the result is always a valid, URL-safe media filename. A missing
        // extension makes later `media://` links fatal in Grav's Excerpts
        // parser, and spaces / odd characters break the Markdown image URL the
        // editor generates (getgrav/grav-plugin-admin2#77).
        $originalExt = pathinfo($from, PATHINFO_EXTENSION);
        $rawTo = str_replace('\\', '/', (string) $body['to']);
        $toDir = trim(dirname($rawTo), '/.');
        $toName = $this->sanitizeMediaFilename(basename($rawTo), $originalExt);
        $to = $this->validateRelativePath(($toDir !== '' ? $toDir . '/' : '') . $toName, $mediaPath);

        $fromAbsolute = $mediaPath . '/' . $from;
        $toAbsolute = $mediaPath . '/' . $to;

        if (!file_exists($fromAbsolute)) {
            throw new NotFoundException("Source file not found.");
        }

        // Sanitizing can map the requested name back onto the source (e.g. only
        // the extension was dropped) — treat that as a no-op rather than a
        // "destination exists" error.
        if ($fromAbsolute === $toAbsolute) {
            $targetPath = $toDir !== '' ? $mediaPath . '/' . $toDir : $mediaPath;
            return ApiResponse::ok($this->serializeSiteFile($targetPath, $toName, $toDir));
        }

        if (file_exists($toAbsolute)) {
            throw new ValidationException("A file already exists at the destination.");
        }

        // Ensure target directory exists
        $targetDir = dirname($toAbsolute);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true)) {
            throw new ValidationException('Unable to create destination directory.');
        }

        if (!rename($fromAbsolute, $toAbsolute)) {
            throw new ValidationException('Unable to rename file.');
        }

        // Also rename metadata sidecar if it exists
        $fromMeta = $fromAbsolute . '.meta.yaml';
        $toMeta = $toAbsolute . '.meta.yaml';
        if (file_exists($fromMeta)) {
            rename($fromMeta, $toMeta);
        }

        // Keep order sidecars coherent across the rename/move.
        $this->renameInSiteMediaOrder(
            dirname($fromAbsolute),
            basename($fromAbsolute),
            dirname($toAbsolute),
            basename($toAbsolute),
        );

        $toDir = ltrim(dirname($to) === '.' ? '' : dirname($to), '/');
        $toFilename = basename($to);

        $targetPath = $toDir !== '' ? $mediaPath . '/' . $toDir : $mediaPath;

        return ApiResponse::ok(
            $this->serializeSiteFile($targetPath, $toFilename, $toDir),
            $this->invalidationHeaders([
                'media:delete:' . $from,
                'media:create:' . $to,
                'media:list',
            ]),
        );
    }

    /**
     * POST /media/folders/rename - Rename a folder.
     */
    public function renameFolder(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $body = json_decode((string) $request->getBody(), true) ?? [];

        if (empty($body['from']) || empty($body['to'])) {
            throw new ValidationException("Both 'from' and 'to' paths are required.");
        }

        $from = $this->validateRelativePath($body['from'], $mediaPath);
        $to = $this->validateRelativePath($body['to'], $mediaPath);

        $fromAbsolute = $mediaPath . '/' . $from;
        $toAbsolute = $mediaPath . '/' . $to;

        if (!is_dir($fromAbsolute)) {
            throw new NotFoundException("Source folder not found.");
        }

        if (file_exists($toAbsolute)) {
            throw new ValidationException("A folder already exists at the destination.");
        }

        if (!rename($fromAbsolute, $toAbsolute)) {
            throw new ValidationException('Unable to rename folder.');
        }

        $name = basename($to);
        $data = [
            'name' => $name,
            'path' => $to,
            'children_count' => 0,
            'file_count' => 0,
        ];

        return ApiResponse::ok(
            $data,
            $this->invalidationHeaders([
                'media:delete:' . $from,
                'media:create:' . $to,
                'media:list',
            ]),
        );
    }

    /**
     * POST /media/order - Persist a manual ordering of files in a site media
     * folder. Body: { path?: string, order: string[] }. Writes a per-folder
     * `media_order.yaml` sidecar that `siteMedia` applies when listing.
     */
    public function setSiteMediaOrder(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();
        $body = json_decode((string) $request->getBody(), true) ?? [];

        $relativePath = '';
        if (!empty($body['path'])) {
            $relativePath = $this->validateRelativePath($body['path'], $mediaPath);
        }

        $folderAbs = $relativePath !== '' ? $mediaPath . '/' . $relativePath : $mediaPath;
        if (!is_dir($folderAbs)) {
            throw new NotFoundException('Folder not found.');
        }

        if (!isset($body['order']) || !is_array($body['order'])) {
            throw new ValidationException("An 'order' array of filenames is required.");
        }

        // Reduce to safe basenames; drop blanks and the sidecar itself.
        $order = array_values(array_filter(
            array_map(static fn($n) => is_string($n) ? basename($n) : '', $body['order']),
            static fn(string $n) => $n !== '' && $n !== self::MEDIA_ORDER_FILE,
        ));

        $this->writeSiteMediaOrder($folderAbs, $order);

        return ApiResponse::noContent(
            $this->invalidationHeaders([
                'media:update:' . ($relativePath !== '' ? $relativePath : '/'),
                'media:list',
            ]),
        );
    }

    // -------------------------------------------------------------------------
    // Site media ordering (per-folder media_order.yaml sidecar)
    // -------------------------------------------------------------------------

    /**
     * Read the ordered filename list from a folder's order sidecar.
     *
     * @return string[]
     */
    private function readSiteMediaOrder(string $folderAbs): array
    {
        $file = $folderAbs . '/' . self::MEDIA_ORDER_FILE;
        if (!is_file($file)) {
            return [];
        }

        $data = Yaml::parse((string) file_get_contents($file)) ?: [];
        $order = $data['media_order'] ?? [];

        return is_array($order)
            ? array_values(array_filter($order, 'is_string'))
            : [];
    }

    /**
     * Write (or clear) a folder's order sidecar. An empty order removes it.
     *
     * @param string[] $order
     */
    private function writeSiteMediaOrder(string $folderAbs, array $order): void
    {
        $file = $folderAbs . '/' . self::MEDIA_ORDER_FILE;

        if ($order === []) {
            if (is_file($file)) {
                @unlink($file);
            }
            return;
        }

        file_put_contents($file, Yaml::dump(['media_order' => array_values($order)], 99, 2));
    }

    /**
     * Order a folder's file list by its saved sidecar. Files not listed (e.g.
     * new uploads) keep their incoming order and follow the ordered ones.
     *
     * @param string[] $files
     * @return string[]
     */
    private function applySiteMediaOrder(array $files, string $folderAbs): array
    {
        $order = $this->readSiteMediaOrder($folderAbs);
        if ($order === []) {
            return $files;
        }

        $ordered = [];
        foreach ($order as $name) {
            $idx = array_search($name, $files, true);
            if ($idx !== false) {
                $ordered[] = $files[$idx];
                unset($files[$idx]);
            }
        }
        foreach ($files as $name) {
            $ordered[] = $name;
        }

        return $ordered;
    }

    /**
     * Drop a filename from a folder's order sidecar (best-effort, on delete).
     */
    private function removeFromSiteMediaOrder(string $folderAbs, string $filename): void
    {
        $order = $this->readSiteMediaOrder($folderAbs);
        if ($order === []) {
            return;
        }

        $next = array_values(array_filter($order, static fn(string $n) => $n !== $filename));
        if ($next !== $order) {
            $this->writeSiteMediaOrder($folderAbs, $next);
        }
    }

    /**
     * Keep order sidecars coherent across a rename/move (best-effort). Same
     * folder: rename the entry in place. Cross folder: drop it from the source.
     */
    private function renameInSiteMediaOrder(string $fromFolderAbs, string $fromName, string $toFolderAbs, string $toName): void
    {
        if ($fromFolderAbs === $toFolderAbs) {
            $order = $this->readSiteMediaOrder($fromFolderAbs);
            if ($order === []) {
                return;
            }
            $next = array_map(static fn(string $n) => $n === $fromName ? $toName : $n, $order);
            if ($next !== $order) {
                $this->writeSiteMediaOrder($fromFolderAbs, $next);
            }
            return;
        }

        $this->removeFromSiteMediaOrder($fromFolderAbs, $fromName);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * GET /thumbnails/{hash}.{ext} - Serve a cached thumbnail image.
     */
    public function thumbnail(ServerRequestInterface $request): ResponseInterface
    {
        $file = $this->getRouteParam($request, 'file');
        if (!$file) {
            throw new NotFoundException('Thumbnail not found.');
        }

        $cacheDir = $this->grav['locator']->findResource('cache://') . '/api/thumbnails';
        $cachePath = $cacheDir . '/' . basename($file);

        if (!file_exists($cachePath)) {
            throw new NotFoundException('Thumbnail not found.');
        }

        $mime = mime_content_type($cachePath) ?: 'application/octet-stream';
        $content = file_get_contents($cachePath);

        return new Response(
            200,
            [
                'Content-Type' => $mime,
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
            $content
        );
    }

    /**
     * Resolve a page from the route parameter or throw a 404.
     */
    private function findPageOrFail(ServerRequestInterface $request): PageInterface
    {
        $route = $this->getRouteParam($request, 'route');

        if ($route === null || $route === '') {
            throw new NotFoundException('Page route is required.');
        }

        $page = $this->resolvePageByRoute($route);

        if (!$page) {
            throw new NotFoundException("Page '/{$route}' not found.");
        }

        return $page;
    }

    /**
     * Clean a user-supplied media filename into one that is safe on disk and
     * valid inside a Markdown `media://` URL, re-attaching the source file's
     * extension. Spaces (and runs of whitespace) collapse to a single dash, and
     * characters outside unicode letters/digits plus `. _ -` are dropped, so
     * `Test Image` with a `.jpg` source becomes `Test-Image.jpg`. Forcing the
     * original extension also stops an extension-less rename, which otherwise
     * produces a `media://` link that fatals in Grav's Excerpts parser
     * (getgrav/grav-plugin-admin2#77).
     */
    private function sanitizeMediaFilename(string $newName, string $extension): string
    {
        // Work on the stem only; the source extension is re-attached below so a
        // rename can never change (or drop) the file type.
        $stem = pathinfo($newName, PATHINFO_FILENAME);
        $stem = preg_replace('/\s+/u', '-', trim($stem));
        $stem = preg_replace('/[^\p{L}\p{N}._-]+/u', '', (string) $stem);
        $stem = trim((string) preg_replace('/-{2,}/', '-', (string) $stem), '-_.');

        if ($stem === '') {
            throw new ValidationException('The new name must contain at least one letter or number.');
        }

        return $extension !== '' ? $stem . '.' . $extension : $stem;
    }

    /**
     * Validate a relative path is safe and within the media directory.
     * Returns the sanitized relative path.
     */
    private function validateRelativePath(string $path, string $basePath): string
    {
        // Normalize separators
        $path = str_replace('\\', '/', $path);
        $path = trim($path, '/');

        if ($path === '') {
            return '';
        }

        // Check each segment
        foreach (explode('/', $path) as $segment) {
            if (
                $segment === '' ||
                $segment === '.' ||
                $segment === '..' ||
                str_contains($segment, "\0") ||
                str_starts_with($segment, '.')
            ) {
                throw new ValidationException("Invalid path: '{$path}'.");
            }
        }

        // Verify resolved path is within base
        $absolute = $basePath . '/' . $path;

        // For existing paths, use realpath
        if (file_exists($absolute)) {
            $real = realpath($absolute);
            $realBase = realpath($basePath);
            if ($real === false || $realBase === false || !str_starts_with($real, $realBase . '/')) {
                throw new ValidationException("Invalid path: '{$path}'.");
            }
        }

        return $path;
    }

    /**
     * Extract and validate a relative file path from route parameters.
     * Unlike getSafeFilename() which strips directories with basename(),
     * this preserves path components for subfolder support.
     */
    private function getSafeRelativeFilePath(ServerRequestInterface $request, string $basePath): string
    {
        $filename = $this->getRouteParam($request, 'filename');

        if ($filename === null || $filename === '') {
            throw new ValidationException('Filename is required.');
        }

        // Normalize
        $filename = str_replace('\\', '/', $filename);
        $filename = trim($filename, '/');

        // Validate each path segment
        foreach (explode('/', $filename) as $segment) {
            if (
                $segment === '' ||
                $segment === '.' ||
                $segment === '..' ||
                str_contains($segment, "\0") ||
                str_starts_with($segment, '.')
            ) {
                throw new ValidationException('Invalid filename.');
            }
        }

        // Verify resolved path is within base
        $absolute = $basePath . '/' . $filename;
        if (file_exists($absolute)) {
            $real = realpath($absolute);
            $realBase = realpath($basePath);
            if ($real === false || $realBase === false || !str_starts_with($real, $realBase . '/')) {
                throw new ValidationException('Invalid filename.');
            }
        }

        return $filename;
    }

    /**
     * Resolve the absolute path to the site-level media directory.
     */
    private function getSiteMediaPath(): string
    {
        /** @var \Grav\Common\Locator $locator */
        $locator = $this->grav['locator'];

        $path = $locator->findResource('user://media', true, true);

        if (!$path) {
            throw new NotFoundException('Site media directory could not be resolved.');
        }

        return $path;
    }

    /**
     * Handle recursive media search across all subfolders.
     */
    private function handleMediaSearch(
        ServerRequestInterface $request,
        string $mediaPath,
        array $queryParams
    ): ResponseInterface {
        $search = strtolower($queryParams['search']);
        $typeFilter = $queryParams['type'] ?? null;
        $pagination = $this->getPagination($request);

        $matches = [];

        if (is_dir($mediaPath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($mediaPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    continue;
                }

                $name = $item->getFilename();

                // Skip hidden and metadata files
                if (str_starts_with($name, '.') || str_ends_with($name, '.meta.yaml')) {
                    continue;
                }

                // Match filename
                if (!str_contains(strtolower($name), $search)) {
                    continue;
                }

                // Apply type filter
                if ($typeFilter) {
                    $mime = mime_content_type($item->getPathname()) ?: '';
                    $passesFilter = match ($typeFilter) {
                        'image' => str_starts_with($mime, 'image/'),
                        'video' => str_starts_with($mime, 'video/'),
                        'audio' => str_starts_with($mime, 'audio/'),
                        'document' => !str_starts_with($mime, 'image/') && !str_starts_with($mime, 'video/') && !str_starts_with($mime, 'audio/'),
                        default => true,
                    };
                    if (!$passesFilter) {
                        continue;
                    }
                }

                // Calculate relative path
                $fullPath = $item->getPathname();
                $relDir = ltrim(str_replace($mediaPath, '', dirname($fullPath)), '/');

                $matches[] = ['filename' => $name, 'dir' => $relDir, 'fullPath' => $fullPath];
            }
        }

        // Sort matches
        usort($matches, fn($a, $b) => strnatcasecmp($a['filename'], $b['filename']));

        $total = count($matches);
        $paged = array_slice($matches, $pagination['offset'], $pagination['limit']);

        $serialized = array_map(function (array $match) {
            return $this->serializeSiteFile(dirname($match['fullPath']), $match['filename'], $match['dir']);
        }, $paged);

        $baseUrl = $this->getApiBaseUrl() . '/media';

        return ApiResponse::paginated(
            $serialized,
            $total,
            $pagination['page'],
            $pagination['per_page'],
            $baseUrl,
            200,
            [],
            [
                'path' => '',
                'folders' => [],
                'search' => $queryParams['search'],
            ],
        );
    }

    /**
     * Scan a directory for media files, returning just the filenames sorted alphabetically.
     *
     * @return string[]
     */
    private function scanMediaDirectory(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $item */
        foreach (new \DirectoryIterator($path) as $item) {
            if ($item->isDot() || $item->isDir()) {
                continue;
            }

            // Skip hidden files and metadata files
            $name = $item->getFilename();
            if (str_starts_with($name, '.') || str_ends_with($name, '.meta.yaml')) {
                continue;
            }

            $files[] = $name;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * Scan a directory for media files and subdirectories.
     *
     * @return array{files: string[], folders: array<array{name: string, path: string, children_count: int, file_count: int}>}
     */
    private function scanMediaDirectoryWithFolders(string $absolutePath, string $relativePath = ''): array
    {
        $files = [];
        $folders = [];

        if (!is_dir($absolutePath)) {
            return ['files' => $files, 'folders' => $folders];
        }

        foreach (new \DirectoryIterator($absolutePath) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $name = $item->getFilename();

            // Skip hidden files/dirs
            if (str_starts_with($name, '.')) {
                continue;
            }

            if ($item->isDir()) {
                $folderPath = $relativePath !== '' ? $relativePath . '/' . $name : $name;
                $childPath = $absolutePath . '/' . $name;

                // Count immediate children
                $childrenCount = 0;
                $fileCount = 0;
                if (is_dir($childPath)) {
                    foreach (new \DirectoryIterator($childPath) as $child) {
                        if ($child->isDot() || str_starts_with($child->getFilename(), '.')) {
                            continue;
                        }
                        if ($child->isDir()) {
                            $childrenCount++;
                        } elseif (!str_ends_with($child->getFilename(), '.meta.yaml') && $child->getFilename() !== self::MEDIA_ORDER_FILE) {
                            $fileCount++;
                        }
                    }
                }

                $folders[] = [
                    'name' => $name,
                    'path' => $folderPath,
                    'children_count' => $childrenCount,
                    'file_count' => $fileCount,
                ];
            } else {
                // Skip metadata files and the order sidecar
                if (str_ends_with($name, '.meta.yaml') || $name === self::MEDIA_ORDER_FILE) {
                    continue;
                }
                $files[] = $name;
            }
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        usort($folders, fn(array $a, array $b) => strnatcasecmp($a['name'], $b['name']));

        return ['files' => $files, 'folders' => $folders];
    }

    /**
     * Build a serialized array for a raw file in the site media directory.
     * Used when we don't have Grav Medium objects available.
     */
    private function serializeSiteFile(string $basePath, string $filename, string $relativePath = ''): array
    {
        $filePath = $basePath . '/' . $filename;
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';

        $fullRelativePath = $relativePath !== '' ? $relativePath . '/' . $filename : $filename;

        $data = [
            'filename' => $filename,
            'path' => $relativePath,
            'url' => '/user/media/' . $fullRelativePath,
            'type' => $mime,
            'size' => (int) filesize($filePath),
        ];

        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            if ($imageSize = @getimagesize($filePath)) {
                $data['dimensions'] = [
                    'width' => $imageSize[0],
                    'height' => $imageSize[1],
                ];
            }

            // Generate thumbnail
            try {
                $thumbnailService = $this->getThumbnailService();
                $hash = $thumbnailService->getOrCreate($filePath);
                if ($hash) {
                    $data['thumbnail_url'] = $this->getApiBaseUrl() . '/thumbnails/' . $hash;
                }
            } catch (\Throwable) {
                // Thumbnail generation failed — skip it
            }
        }

        $mtime = filemtime($filePath);
        $data['modified'] = date(\DateTimeInterface::ATOM, $mtime ?: time());

        return $data;
    }
}
