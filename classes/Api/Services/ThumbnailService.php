<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

class ThumbnailService
{
    /**
     * What a thumbnail's filename looks like: getHash() plus an output
     * extension. The thumbnail route serves nothing else.
     */
    public const FILENAME_PATTERN = '/^[a-f0-9]{32}\.(?:jpg|png|gif|webp|avif)$/';

    /** Where deferred thumbnails wait for their first request, under the cache dir. */
    private const PENDING_DIR = 'pending';

    private string $cacheDir;
    private int $maxSize;
    private int $quality;

    public function __construct(string $cacheDir, int $maxSize = 500, int $quality = 85)
    {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->maxSize = $maxSize;
        $this->quality = $quality;

        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException(sprintf('Unable to create thumbnail cache directory "%s"', $this->cacheDir));
        }
    }

    /**
     * The service for Grav's thumbnail cache (`cache://api/thumbnails`), which
     * the `/thumbnails/{file}` route serves.
     */
    public static function forGrav(\Grav\Common\Grav $grav, int $maxSize = 500): self
    {
        return new self($grav['locator']->findResource('cache://', true, true) . '/api/thumbnails', $maxSize);
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
     * Resolve the cached thumbnail filename (hash.ext) for a source image,
     * generating the thumbnail only when the cache entry is missing.
     *
     * This is the single-pass path for listings: calling getThumbnailFilename()
     * followed by getThumbnail() sniffs the file's magic bytes and stats it
     * twice per item, which dominates the warm-path cost of serializing a
     * media-heavy page. Callers that already know the mime type (media
     * metadata) should pass it so the sniff is skipped entirely.
     *
     * Returns null if the source is not a supported raster image.
     */
    public function ensureThumbnail(string $sourcePath, ?string $mime = null): ?string
    {
        if (!file_exists($sourcePath)) {
            return null;
        }

        $sniffed = $mime === null;
        if ($sniffed) {
            $mime = mime_content_type($sourcePath) ?: null;
        }
        if (!$mime || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        $filename = $this->getHash($sourcePath) . '.' . $this->getOutputExtension($mime);
        $cachePath = $this->cacheDir . '/' . $filename;

        if (file_exists($cachePath) || $this->generate($sourcePath, $cachePath, $mime)) {
            return $filename;
        }

        // A caller-supplied mime can lie (misnamed extension, stale metadata);
        // the sniffed type is authoritative, so retry once with it.
        return $sniffed ? null : $this->ensureThumbnail($sourcePath);
    }

    /**
     * The thumbnail filename (hash.ext) for a source image, WITHOUT generating
     * the image: listings call this for every row, and resizing a folder of
     * photos inside a GET took seconds after a cache clear.
     *
     * When the thumbnail isn't in the cache yet, the source is recorded as
     * pending, and the first request for the filename generates it (see
     * generatePending()). Only a source recorded here can be generated that way,
     * at the size recorded with it, so the public thumbnail route can't be used
     * to resize arbitrary files or to arbitrary sizes.
     *
     * Callers that already know the mime type should pass it so the magic-byte
     * sniff is skipped. Returns null if the source is not a supported raster
     * image.
     */
    public function thumbnailFilename(string $sourcePath, ?string $mime = null): ?string
    {
        if (!is_file($sourcePath)) {
            return null;
        }

        $mime ??= mime_content_type($sourcePath) ?: null;
        if (!$mime || !str_starts_with($mime, 'image/') || $mime === 'image/svg+xml') {
            return null;
        }

        $filename = $this->getHash($sourcePath) . '.' . $this->getOutputExtension($mime);
        if (is_file($this->cacheDir . '/' . $filename)) {
            return $filename;
        }

        $pending = $this->pendingPath($filename);
        if (is_file($pending)) {
            return $filename;
        }

        $record = json_encode([
            'source' => $sourcePath,
            'size' => $this->maxSize,
            'quality' => $this->quality,
            'mime' => $mime,
        ], JSON_UNESCAPED_SLASHES);

        $dir = dirname($pending);
        if ((!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir))
            || $record === false
            || !$this->writeAtomically($pending, $record)) {
            // No record, no deferred thumbnail: fall back to generating now.
            return $this->ensureThumbnail($sourcePath, $mime);
        }

        return $filename;
    }

    /**
     * Absolute path of a cached thumbnail by its filename, or null when the
     * name isn't a thumbnail filename or the file isn't there.
     */
    public function cachedPath(string $filename): ?string
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }
        $path = $this->cacheDir . '/' . $filename;

        return is_file($path) ? $path : null;
    }

    /**
     * Generate a thumbnail that thumbnailFilename() deferred, on the first
     * request for it. Returns the cached thumbnail's path, or null when the
     * filename was never handed out, its source has changed or gone since
     * (the hash no longer matches), or the image can't be decoded.
     */
    public function generatePending(string $filename): ?string
    {
        if (!preg_match(self::FILENAME_PATTERN, $filename)) {
            return null;
        }

        $pending = $this->pendingPath($filename);
        $record = is_file($pending) ? json_decode((string) @file_get_contents($pending), true) : null;
        if (!is_array($record)) {
            return null;
        }

        $source = $record['source'] ?? null;
        $size = $record['size'] ?? null;
        $quality = $record['quality'] ?? null;
        $mime = $record['mime'] ?? null;
        if (!is_string($source) || !is_int($size) || $size < 1 || !is_int($quality) || !is_string($mime)) {
            @unlink($pending);

            return null;
        }

        $service = new self($this->cacheDir, $size, $quality);
        $cachePath = $this->cacheDir . '/' . $filename;
        $hash = substr($filename, 0, 32);

        // The record is for one version of one file: a source edited or
        // removed since then gets a new hash in the next listing.
        if (!is_file($source) || $service->getHash($source) !== $hash) {
            @unlink($pending);

            return null;
        }

        $result = is_file($cachePath) ? $cachePath : $service->generate($source, $cachePath, $mime);
        if ($result === null) {
            // A caller-supplied mime can lie (misnamed extension, stale
            // metadata); the sniffed type is authoritative, so retry once.
            $sniffed = mime_content_type($source) ?: null;
            if ($sniffed && $sniffed !== $mime && str_starts_with($sniffed, 'image/') && $sniffed !== 'image/svg+xml') {
                $result = $service->generate($source, $cachePath, $sniffed);
            }
        }

        @unlink($pending);

        return $result;
    }

    private function pendingPath(string $filename): string
    {
        return $this->cacheDir . '/' . self::PENDING_DIR . '/' . $filename . '.json';
    }

    /**
     * Write through a temp file and a rename, so a concurrent reader sees
     * either nothing or the whole file.
     */
    private function writeAtomically(string $path, string $contents): bool
    {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $contents) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
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
        // Suppressed so the caller's null check is reached: an unwritable
        // thumbnail cache should degrade to serving no thumbnail, not fatal the
        // whole media listing on an unsilenced GD warning (#30).
        //
        // Written to a temp file and renamed into place: the thumbnail route
        // can now generate on request, so two requests may race for the same
        // file, and neither may serve the other's half-written image.
        $tmp = $cachePath . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $result = match ($mime) {
            'image/png' => @imagepng($image, $tmp, 6),
            'image/gif' => @imagegif($image, $tmp),
            'image/webp' => @imagewebp($image, $tmp, $this->quality),
            'image/avif' => function_exists('imageavif') ? @imageavif($image, $tmp, $this->quality) : false,
            default => @imagejpeg($image, $tmp, $this->quality),
        };

        if (!$result || !@rename($tmp, $cachePath)) {
            @unlink($tmp);

            return null;
        }

        return $cachePath;
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
