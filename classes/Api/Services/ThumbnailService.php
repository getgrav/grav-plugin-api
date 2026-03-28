<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

class ThumbnailService
{
    private string $cacheDir;
    private int $maxSize;
    private int $quality;

    public function __construct(string $cacheDir, int $maxSize = 500, int $quality = 85)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->maxSize = $maxSize;
        $this->quality = $quality;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get the hash for a thumbnail based on source path and modification time.
     */
    public function getHash(string $sourcePath): string
    {
        $mtime = file_exists($sourcePath) ? filemtime($sourcePath) : 0;
        return md5($sourcePath . '|' . $mtime . '|' . $this->maxSize);
    }

    /**
     * Get the thumbnail filename (hash.ext) for a source image.
     * Returns null if not a supported image.
     */
    public function getThumbnailFilename(string $sourcePath): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $mime = mime_content_type($sourcePath);
        if (!$mime || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        return $this->getHash($sourcePath) . '.' . $this->getOutputExtension($mime);
    }

    /**
     * Get the cached thumbnail path, generating it if needed.
     * Returns null if the source is not a supported image.
     */
    public function getThumbnail(string $sourcePath): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $mime = mime_content_type($sourcePath);
        if (!$mime || !str_starts_with($mime, 'image/')) {
            return null;
        }

        // Skip SVGs — serve as-is
        if ($mime === 'image/svg+xml') {
            return null;
        }

        $hash = $this->getHash($sourcePath);
        $ext = $this->getOutputExtension($mime);
        $cachePath = $this->cacheDir . '/' . $hash . '.' . $ext;

        if (file_exists($cachePath)) {
            return $cachePath;
        }

        return $this->generate($sourcePath, $cachePath, $mime);
    }

    /**
     * Generate a thumbnail and save to cache.
     */
    private function generate(string $sourcePath, string $cachePath, string $mime): ?string
    {
        $sourceImage = $this->loadImage($sourcePath, $mime);
        if (!$sourceImage) {
            return null;
        }

        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);

        // Already small enough — cache as-is so we don't re-check every time
        if ($origWidth <= $this->maxSize && $origHeight <= $this->maxSize) {
            return $this->saveImage($sourceImage, $cachePath, $mime, $origWidth, $origHeight);
        }

        // Calculate new dimensions maintaining aspect ratio
        if ($origWidth >= $origHeight) {
            $newWidth = $this->maxSize;
            $newHeight = (int) round($origHeight * ($this->maxSize / $origWidth));
        } else {
            $newHeight = $this->maxSize;
            $newWidth = (int) round($origWidth * ($this->maxSize / $origHeight));
        }

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if (!$thumb) {
            imagedestroy($sourceImage);
            return null;
        }

        // Preserve transparency for PNG/WebP
        if ($mime === 'image/png' || $mime === 'image/webp') {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
            imagefill($thumb, 0, 0, $transparent);
        }

        imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($sourceImage);

        return $this->saveImage($thumb, $cachePath, $mime, $newWidth, $newHeight);
    }

    /**
     * Load an image resource from file.
     */
    private function loadImage(string $path, string $mime): ?\GdImage
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/gif' => @imagecreatefromgif($path) ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/avif' => function_exists('imagecreatefromavif') ? (@imagecreatefromavif($path) ?: null) : null,
            default => null,
        };
    }

    /**
     * Save an image resource to the cache path.
     */
    private function saveImage(\GdImage $image, string $cachePath, string $mime, int $width, int $height): ?string
    {
        $result = match ($mime) {
            'image/png' => imagepng($image, $cachePath, 6),
            'image/gif' => imagegif($image, $cachePath),
            'image/webp' => imagewebp($image, $cachePath, $this->quality),
            'image/avif' => function_exists('imageavif') ? imageavif($image, $cachePath, $this->quality) : false,
            default => imagejpeg($image, $cachePath, $this->quality),
        };

        imagedestroy($image);

        return $result ? $cachePath : null;
    }

    /**
     * Get the output file extension for a MIME type.
     */
    private function getOutputExtension(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'jpg',
        };
    }
}
