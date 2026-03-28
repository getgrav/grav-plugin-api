<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use Grav\Plugin\Api\Services\ThumbnailService;

class MediaSerializer implements SerializerInterface
{
    public function __construct(
        private ?ThumbnailService $thumbnailService = null,
        private string $thumbnailBaseUrl = '',
    ) {}

    /**
     * Serialize a single Grav Medium object to an API response array.
     */
    public function serialize(object $medium, array $options = []): array
    {
        $mime = $medium->get('mime') ?? 'application/octet-stream';

        $data = [
            'filename' => $medium->filename,
            'url' => $medium->url(),
            'type' => $mime,
            'size' => (int) ($medium->get('size') ?? 0),
        ];

        if (str_starts_with($mime, 'image/')) {
            $width = $medium->get('width');
            $height = $medium->get('height');

            if ($width !== null && $height !== null) {
                $data['dimensions'] = [
                    'width' => (int) $width,
                    'height' => (int) $height,
                ];
            }

            // Generate thumbnail URL for images
            if ($this->thumbnailService) {
                $sourcePath = $this->resolveSourcePath($medium);
                if ($sourcePath) {
                    $thumbFilename = $this->thumbnailService->getThumbnailFilename($sourcePath);
                    if ($thumbFilename) {
                        // Trigger thumbnail generation
                        $this->thumbnailService->getThumbnail($sourcePath);
                        $data['thumbnail_url'] = $this->thumbnailBaseUrl . '/thumbnails/' . $thumbFilename;
                    }
                }
            }
        }

        $data['modified'] = $this->resolveModifiedTime($medium);

        return $data;
    }

    /**
     * Serialize an iterable collection of Medium objects.
     */
    public function serializeCollection(iterable $media, array $options = []): array
    {
        $items = [];

        foreach ($media as $medium) {
            $items[] = $this->serialize($medium, $options);
        }

        return $items;
    }

    /**
     * Resolve the physical file path for a medium.
     */
    private function resolveSourcePath(object $medium): ?string
    {
        if (method_exists($medium, 'path')) {
            $path = $medium->path();
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Resolve the last-modified timestamp for a medium, returning an ISO 8601 string.
     */
    private function resolveModifiedTime(object $medium): string
    {
        $timestamp = null;

        if (method_exists($medium, 'modified')) {
            $timestamp = $medium->modified();
        }

        if (!$timestamp && method_exists($medium, 'path')) {
            $path = $medium->path();
            if ($path && file_exists($path)) {
                $timestamp = filemtime($path);
            }
        }

        $timestamp = $timestamp ?: time();

        return date(\DateTimeInterface::ATOM, (int) $timestamp);
    }
}
