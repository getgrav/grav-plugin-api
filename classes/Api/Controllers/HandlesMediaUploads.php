<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Controllers;

use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Serializers\MediaSerializer;
use Grav\Plugin\Api\Services\ThumbnailService;
use Grav\Plugin\Api\Services\UploadFieldSettings;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Shared file-upload pipeline for media endpoints: validating uploads,
 * moving them into a target folder, and serializing the resulting media.
 *
 * The logic is storage-agnostic — it only needs a resolved filesystem
 * directory to write into. That makes it reusable for any object that can
 * yield a media folder: a page (its content folder) or a folder-stored Flex
 * object (its storage folder, e.g. user-data://flex-objects/contacts/{id}).
 *
 * Used by MediaController (pages + site media) and by the flex-objects
 * plugin's FlexApiController via the shared AbstractApiController base.
 */
trait HandlesMediaUploads
{
    /** Maximum upload size: 64 MB */
    private const int MAX_UPLOAD_SIZE = 67_108_864;

    private ?MediaSerializer $mediaSerializer = null;

    protected function getThumbnailService(): ThumbnailService
    {
        $cacheDir = $this->grav['locator']->findResource('cache://') . '/api/thumbnails';
        return new ThumbnailService($cacheDir);
    }

    protected function getSerializer(): MediaSerializer
    {
        if (!$this->mediaSerializer) {
            $thumbnailService = $this->getThumbnailService();
            $baseUrl = $this->getApiBaseUrl();
            $this->mediaSerializer = new MediaSerializer($thumbnailService, $baseUrl);
        }
        return $this->mediaSerializer;
    }

    /**
     * Extract and validate a safe filename from the route parameters.
     */
    protected function getSafeFilename(ServerRequestInterface $request): string
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
     * Parse blueprint file-field upload settings (random_name, avoid_overwriting,
     * accept, filesize) from a request's form fields. Absent settings yield an
     * inert object, so callers without field context keep current behavior.
     */
    protected function parseUploadFieldSettings(ServerRequestInterface $request): UploadFieldSettings
    {
        $body = $request->getParsedBody();

        return is_array($body) ? UploadFieldSettings::fromParams($body) : UploadFieldSettings::none();
    }

    /**
     * Process a single uploaded file: validate it and move to the target directory.
     *
     * Optional per-field $settings (from a blueprint `type: file` field) layer
     * filename randomization, overwrite avoidance, an accept allowlist, and a
     * per-field size limit *on top of* the immovable security floor enforced
     * here (size cap, traversal guard, dangerous-extension denylist).
     *
     * Returns the safe filename that was written.
     */
    protected function processUploadedFile(
        UploadedFileInterface $file,
        string $targetDir,
        ?UploadFieldSettings $settings = null,
    ): string {
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

        // Validate file size against the hard cap, then the per-field limit.
        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_UPLOAD_SIZE) {
            throw new ValidationException(
                sprintf('File exceeds maximum allowed size of %d MB.', self::MAX_UPLOAD_SIZE / 1_048_576)
            );
        }
        $settings?->assertFilesize($size);

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

        // Validate extension against dangerous extensions list, then the
        // field's accept allowlist (matched on the original name's extension).
        $this->validateFileExtension($filename);
        $settings?->assertAccepted($filename);

        // Apply random_name / avoid_overwriting last — both preserve the
        // already-validated extension, so the floor checks above still hold.
        if ($settings !== null) {
            $filename = $settings->resolveFilename($filename, $targetDir);
        }

        // Move the file to the target directory
        $targetPath = $targetDir . '/' . $filename;
        $file->moveTo($targetPath);

        return $filename;
    }

    /**
     * Validate that a filename's extension is not on the dangerous list.
     */
    protected function validateFileExtension(string $filename): void
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
    protected function flattenUploadedFiles(array $files): array
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
}
