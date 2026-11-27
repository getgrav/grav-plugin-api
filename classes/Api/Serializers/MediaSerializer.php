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

        // Alt/title come from the `.meta.yaml` sidecar, which Grav merges into
        // the medium's attributes. Exposing them here lets the media panel
        // insert `![alt](file)` instead of overwriting alt with the filename.
        // `alt_text` is the older key core still renders (admin2#227).
        $alt = $medium->get('alt');
        if (!is_string($alt) || $alt === '') {
            $alt = $medium->get('alt_text');
        }
        if (is_string($alt) && $alt !== '') {
            $data['alt'] = $alt;
        }
        $title = $medium->get('title');
        if (is_string($title) && $title !== '') {
            $data['title'] = $title;
        }

        // `@Nx` retina files grouped under this one (admin2#222).
        if ($retina = $this->retinaScales($medium)) {
            $data['retina'] = $retina;
        }

        if (str_starts_with($mime, 'image/')) {
            $width = $medium->get('width');
            $height = $medium->get('height');

            if ($width !== null && $height !== null) {
                $data['dimensions'] = [
                    'width' => (int) $width,
                    'height' => (int) $height,
                ];
            }

            // Thumbnail URL for images. The image itself is resized on the
            // first request for that URL, not here. The medium's own mime is
            // passed through so the service skips per-item magic-byte sniffing.
            if ($this->thumbnailService) {
                $sourcePath = $this->resolveSourcePath($medium);
                if ($sourcePath) {
                    $thumbFilename = $this->thumbnailService->thumbnailFilename($sourcePath, $mime);
                    if ($thumbFilename) {
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
     * Scales of the `@Nx` files on disk that core grouped under this image for
     * its srcset, e.g. [2, 3] for photo@2x.jpg and photo@3x.jpg (admin2#222).
     * Copies core scaled down itself are not counted.
     *
     * @return list<int>
     */
    public static function retinaScales(object $medium): array
    {
        if (!method_exists($medium, 'getAlternatives')) {
            return [];
        }

        $scales = [];
        foreach ($medium->getAlternatives(false) as $alternative) {
            $file = basename((string) $alternative->get('filepath'));
            if (preg_match('/@(\d+)x\.[^.]+$/', $file, $m) && (int) $m[1] >= 2) {
                $scales[(int) $m[1]] = (int) $m[1];
            }
        }
        ksort($scales);

        return array_values($scales);
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
