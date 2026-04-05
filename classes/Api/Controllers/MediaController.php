<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

class MediaController extends AbstractApiController
{
    private ?MediaSerializer $serializer = null;

    /** Maximum upload size: 64 MB */
    private const int MAX_UPLOAD_SIZE = 67_108_864;

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

        $uploadedNames = [];
        foreach ($uploadedFiles as $file) {
            // Fire before event — plugins can throw to reject specific files
            $this->fireEvent('onApiBeforeMediaUpload', [
                'page' => $page,
                'filename' => $file->getClientFilename(),
                'type' => $file->getClientMediaType(),
                'size' => $file->getSize(),
            ]);

            $this->processUploadedFile($file, $pagePath);
            $uploadedNames[] = $file->getClientFilename();
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

        // Verify the file exists on disk
        $filePath = $pagePath . '/' . $filename;
        if (!file_exists($filePath)) {
            throw new NotFoundException("Media file '{$filename}' not found on this page.");
        }

        $this->fireEvent('onApiBeforeMediaDelete', ['page' => $page, 'filename' => $filename]);

        unlink($filePath);

        // Also remove any metadata file (.meta.yaml) if it exists
        $metaPath = $filePath . '.meta.yaml';
        if (file_exists($metaPath)) {
            unlink($metaPath);
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

        $created = [];
        foreach ($uploadedFiles as $file) {
            $filename = $this->processUploadedFile($file, $targetDir);
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
        $to = $this->validateRelativePath($body['to'], $mediaPath);

        $fromAbsolute = $mediaPath . '/' . $from;
        $toAbsolute = $mediaPath . '/' . $to;

        if (!file_exists($fromAbsolute)) {
            throw new NotFoundException("Source file not found.");
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

    private function getThumbnailService(): ThumbnailService
    {
        $cacheDir = $this->grav['locator']->findResource('cache://') . '/api/thumbnails';
        return new ThumbnailService($cacheDir);
    }

    private function getSerializer(): MediaSerializer
    {
        if (!$this->serializer) {
            $thumbnailService = $this->getThumbnailService();
            $baseUrl = $this->getApiBaseUrl();
            $this->serializer = new MediaSerializer($thumbnailService, $baseUrl);
        }
        return $this->serializer;
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

        $pages = $this->grav['pages'];

        // Enable pages if they were disabled (e.g. in admin context)
        if (method_exists($pages, 'enablePages')) {
            $pages->enablePages();
        }

        $page = $pages->find('/' . ltrim($route, '/'));

        if (!$page) {
            throw new NotFoundException("Page '/{$route}' not found.");
        }

        return $page;
    }

    /**
     * Extract and validate a safe filename from the route parameters.
     */
    private function getSafeFilename(ServerRequestInterface $request): string
    {
        $filename = $this->getRouteParam($request, 'filename');

        if ($filename === null || $filename === '') {
            throw new ValidationException('Filename is required.');
        }

        $filename = basename($filename);

        if (
            str_contains($filename, '..')
            || str_contains($filename, "\0")
            || str_starts_with($filename, '.')
        ) {
            throw new ValidationException('Invalid filename.');
        }

        return $filename;
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
     * Process a single uploaded file: validate it and move to the target directory.
     *
     * Returns the safe filename that was written.
     */
    private function processUploadedFile(UploadedFileInterface $file, string $targetDir): string
    {
        // Check for upload errors
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $message = match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds maximum upload size.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                default => 'Unknown upload error.',
            };
            throw new ValidationException($message);
        }

        // Validate file size
        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_SIZE) {
            throw new ValidationException(
                sprintf('File exceeds maximum allowed size of %d MB.', self::MAX_UPLOAD_SIZE / 1_048_576)
            );
        }

        // Sanitize the filename
        $originalName = $file->getClientFilename() ?? 'upload';
        $filename = basename($originalName);

        if (
            str_contains($filename, '..')
            || str_contains($filename, "\0")
            || str_starts_with($filename, '.')
        ) {
            throw new ValidationException("Invalid filename: '{$filename}'.");
        }

        // Validate extension against dangerous extensions list
        $this->validateFileExtension($filename);

        // Move the file to the target directory
        $targetPath = $targetDir . '/' . $filename;
        $file->moveTo($targetPath);

        return $filename;
    }

    /**
     * Validate that a filename's extension is not on the dangerous list.
     */
    private function validateFileExtension(string $filename): void
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($extension === '') {
            throw new ValidationException('Uploaded file must have a file extension.');
        }

        $dangerousExtensions = $this->config->get('security.uploads_dangerous_extensions', []);

        // Normalize to lowercase for comparison
        $dangerousExtensions = array_map('strtolower', $dangerousExtensions);

        if (in_array($extension, $dangerousExtensions, true)) {
            throw new ValidationException(
                "File extension '.{$extension}' is not allowed for security reasons."
            );
        }
    }

    /**
     * Flatten a potentially nested array of uploaded files into a flat list.
     *
     * PSR-7 allows uploaded files to be nested (e.g. files[avatar], files[gallery][]).
     *
     * @return UploadedFileInterface[]
     */
    private function flattenUploadedFiles(array $files): array
    {
        $result = [];

        foreach ($files as $file) {
            if ($file instanceof UploadedFileInterface) {
                $result[] = $file;
            } elseif (is_array($file)) {
                $result = [...$result, ...$this->flattenUploadedFiles($file)];
            }
        }

        return $result;
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
                        } elseif (!str_ends_with($child->getFilename(), '.meta.yaml')) {
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
                // Skip metadata files
                if (str_ends_with($name, '.meta.yaml')) {
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
