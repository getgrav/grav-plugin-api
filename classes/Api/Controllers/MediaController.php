<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Serializers\MediaSerializer;
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
        $media = $page->media()->all();

        $serialized = $this->getSerializer()->serializeCollection($media);

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

        foreach ($uploadedFiles as $file) {
            $this->processUploadedFile($file, $pagePath);
        }

        // Clear media cache so new files are picked up
        $page->media()->reset();

        $media = $page->media()->all();
        $serialized = $this->getSerializer()->serializeCollection($media);

        $baseUrl = $this->getApiBaseUrl();
        $route = $this->getRouteParam($request, 'route') ?? '';
        $location = "{$baseUrl}/pages/{$route}/media";

        return ApiResponse::created($serialized, $location);
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

        // Verify the file actually exists in the page's media collection
        $media = $page->media()->all();
        if (!isset($media[$filename])) {
            throw new NotFoundException("Media file '{$filename}' not found on this page.");
        }

        $filePath = $pagePath . '/' . $filename;

        if (!file_exists($filePath)) {
            throw new NotFoundException("Media file '{$filename}' not found on disk.");
        }

        unlink($filePath);

        // Also remove any metadata file (.meta.yaml) if it exists
        $metaPath = $filePath . '.meta.yaml';
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }

        $page->media()->reset();

        return ApiResponse::noContent();
    }

    /**
     * GET /media - List site-level media.
     */
    public function siteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.read');

        $mediaPath = $this->getSiteMediaPath();
        $pagination = $this->getPagination($request);

        $files = $this->scanMediaDirectory($mediaPath);
        $total = count($files);

        // Apply pagination
        $pagedFiles = array_slice($files, $pagination['offset'], $pagination['limit']);

        // Build serialized output for raw files (no Grav Medium objects for site-level)
        $serialized = array_map(
            fn(string $file) => $this->serializeSiteFile($mediaPath, $file),
            $pagedFiles,
        );

        $baseUrl = $this->getApiBaseUrl() . '/media';

        return ApiResponse::paginated(
            $serialized,
            $total,
            $pagination['page'],
            $pagination['per_page'],
            $baseUrl,
        );
    }

    /**
     * POST /media - Upload file(s) to the site media folder.
     */
    public function uploadSiteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $mediaPath = $this->getSiteMediaPath();

        if (!is_dir($mediaPath) && !mkdir($mediaPath, 0775, true)) {
            throw new ValidationException('Unable to create site media directory.');
        }

        $uploadedFiles = $this->flattenUploadedFiles($request->getUploadedFiles());

        if ($uploadedFiles === []) {
            throw new ValidationException('No files were uploaded.');
        }

        $created = [];
        foreach ($uploadedFiles as $file) {
            $filename = $this->processUploadedFile($file, $mediaPath);
            $created[] = $this->serializeSiteFile($mediaPath, $filename);
        }

        $location = $this->getApiBaseUrl() . '/media';

        return ApiResponse::created($created, $location);
    }

    /**
     * DELETE /media/{filename} - Delete a site media file.
     */
    public function deleteSiteMedia(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.media.write');

        $filename = $this->getSafeFilename($request);
        $mediaPath = $this->getSiteMediaPath();
        $filePath = $mediaPath . '/' . $filename;

        if (!file_exists($filePath)) {
            throw new NotFoundException("Media file '{$filename}' not found.");
        }

        unlink($filePath);

        // Also remove any metadata file if it exists
        $metaPath = $filePath . '.meta.yaml';
        if (file_exists($metaPath)) {
            unlink($metaPath);
        }

        return ApiResponse::noContent();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Lazily instantiate the media serializer.
     */
    private function getSerializer(): MediaSerializer
    {
        return $this->serializer ??= new MediaSerializer();
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
     * Resolve the absolute path to the site-level media directory.
     */
    private function getSiteMediaPath(): string
    {
        /** @var \Grav\Common\Locator $locator */
        $locator = $this->grav['locator'];

        $path = $locator->findResource('user://images', true, true);

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
     * Build a serialized array for a raw file in the site media directory.
     * Used when we don't have Grav Medium objects available.
     */
    private function serializeSiteFile(string $basePath, string $filename): array
    {
        $filePath = $basePath . '/' . $filename;
        $mime = mime_content_type($filePath) ?: 'application/octet-stream';

        $data = [
            'filename' => $filename,
            'url' => '/user/images/' . $filename,
            'type' => $mime,
            'size' => (int) filesize($filePath),
        ];

        if (str_starts_with($mime, 'image/') && ($imageSize = @getimagesize($filePath))) {
            $data['dimensions'] = [
                'width' => $imageSize[0],
                'height' => $imageSize[1],
            ];
        }

        $mtime = filemtime($filePath);
        $data['modified'] = date(\DateTimeInterface::ATOM, $mtime ?: time());

        return $data;
    }
}
