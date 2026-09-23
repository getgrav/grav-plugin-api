<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\ThumbnailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Listings hand out thumbnail URLs without resizing anything; the public
 * `/thumbnails/{file}` route resizes on the first request, but only for a
 * source and size a listing recorded.
 */
#[CoversClass(ThumbnailService::class)]
class ThumbnailServiceDeferredTest extends TestCase
{
    private string $dir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-thumb-deferred-' . bin2hex(random_bytes(4));
        $this->cacheDir = $this->dir . '/cache/api/thumbnails';
        mkdir($this->dir . '/media', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->dir);
    }

    #[Test]
    public function listing_returns_the_filename_without_resizing(): void
    {
        $source = $this->jpeg('photo.jpg', 1200, 800);
        $service = new ThumbnailService($this->cacheDir, 500);

        $filename = $service->thumbnailFilename($source, 'image/jpeg');

        self::assertSame($service->getHash($source) . '.jpg', $filename);
        self::assertFileDoesNotExist($this->cacheDir . '/' . $filename);
        self::assertNull($service->cachedPath($filename));
    }

    #[Test]
    public function first_request_generates_it_at_the_recorded_size(): void
    {
        $source = $this->jpeg('photo.jpg', 1200, 800);
        $filename = (new ThumbnailService($this->cacheDir, 200))->thumbnailFilename($source, 'image/jpeg');

        // The route builds its service with the default size; the size that
        // counts is the one the listing recorded.
        $route = new ThumbnailService($this->cacheDir);
        $path = $route->generatePending($filename);

        self::assertSame($this->cacheDir . '/' . $filename, $path);
        self::assertSame([200, 133], array_slice(getimagesize($path) ?: [], 0, 2));
        self::assertSame($path, $route->cachedPath($filename));

        // The record is used up: a second request is served from the cache.
        self::assertNull($route->generatePending($filename));
        self::assertSame($path, $route->cachedPath($filename));
    }

    #[Test]
    public function the_same_filename_as_the_synchronous_path(): void
    {
        $source = $this->jpeg('photo.jpg', 900, 900);
        $service = new ThumbnailService($this->cacheDir, 500);

        $deferred = $service->thumbnailFilename($source, 'image/jpeg');
        $service->generatePending((string) $deferred);

        self::assertSame($service->ensureThumbnail($source, 'image/jpeg'), $deferred);
    }

    #[Test]
    public function an_existing_thumbnail_is_returned_without_a_record(): void
    {
        $source = $this->jpeg('photo.jpg', 800, 600);
        $service = new ThumbnailService($this->cacheDir, 500);
        $filename = $service->ensureThumbnail($source, 'image/jpeg');

        self::assertSame($filename, $service->thumbnailFilename($source, 'image/jpeg'));
        self::assertDirectoryDoesNotExist($this->cacheDir . '/pending');
    }

    #[Test]
    public function a_filename_no_listing_handed_out_is_not_generated(): void
    {
        $service = new ThumbnailService($this->cacheDir);

        self::assertNull($service->generatePending(md5('anything') . '.jpg'));
        self::assertSame([], glob($this->cacheDir . '/*.jpg') ?: []);
    }

    #[Test]
    public function a_source_changed_since_the_listing_is_not_generated(): void
    {
        $source = $this->jpeg('photo.jpg', 800, 600);
        $service = new ThumbnailService($this->cacheDir);
        $filename = (string) $service->thumbnailFilename($source, 'image/jpeg');

        touch($source, time() + 60);
        clearstatcache();

        self::assertNull($service->generatePending($filename));
        self::assertFileDoesNotExist($this->cacheDir . '/' . $filename);
        self::assertFileDoesNotExist($this->cacheDir . '/pending/' . $filename . '.json');
    }

    #[Test]
    public function a_removed_source_is_not_generated(): void
    {
        $source = $this->jpeg('photo.jpg', 800, 600);
        $service = new ThumbnailService($this->cacheDir);
        $filename = (string) $service->thumbnailFilename($source, 'image/jpeg');

        unlink($source);

        self::assertNull($service->generatePending($filename));
    }

    #[Test]
    public function a_record_pointing_at_another_file_is_refused(): void
    {
        // Even a record rewritten to name some other file only generates when
        // the filename's hash is that file's current hash at that size.
        $source = $this->jpeg('photo.jpg', 800, 600);
        $other = $this->jpeg('other.jpg', 800, 600);
        $service = new ThumbnailService($this->cacheDir);
        $filename = (string) $service->thumbnailFilename($source, 'image/jpeg');

        $record = $this->cacheDir . '/pending/' . $filename . '.json';
        $data = json_decode((string) file_get_contents($record), true);
        file_put_contents($record, json_encode(['source' => $other, 'size' => 4000] + $data));

        self::assertNull($service->generatePending($filename));
        self::assertFileDoesNotExist($this->cacheDir . '/' . $filename);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badNames(): array
    {
        return [
            'traversal' => ['../../user/accounts/admin.yaml'],
            'pending record' => [md5('x') . '.jpg.json'],
            'not a hash' => ['photo.jpg'],
            'short hash' => ['abc.jpg'],
            'unknown extension' => [md5('x') . '.php'],
            'upper case hash' => [strtoupper(md5('x')) . '.jpg'],
            'pending dir' => ['pending'],
        ];
    }

    #[Test]
    #[DataProvider('badNames')]
    public function only_thumbnail_filenames_are_served_or_generated(string $name): void
    {
        $service = new ThumbnailService($this->cacheDir);
        mkdir($this->cacheDir . '/pending', 0775, true);
        $planted = $this->cacheDir . '/' . basename($name);
        if (!is_dir($planted)) {
            file_put_contents($planted, 'x');
        }

        self::assertNull($service->cachedPath($name));
        self::assertNull($service->generatePending($name));
    }

    #[Test]
    public function non_raster_sources_get_no_thumbnail(): void
    {
        $svg = $this->dir . '/media/icon.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $text = $this->dir . '/media/notes.txt';
        file_put_contents($text, 'hello');
        $service = new ThumbnailService($this->cacheDir);

        self::assertNull($service->thumbnailFilename($svg, 'image/svg+xml'));
        self::assertNull($service->thumbnailFilename($text));
        self::assertNull($service->thumbnailFilename($this->dir . '/media/missing.jpg', 'image/jpeg'));
    }

    #[Test]
    public function a_wrong_mime_falls_back_to_the_sniffed_type(): void
    {
        // Stale metadata says PNG; the bytes are JPEG.
        $source = $this->jpeg('really-a-jpeg.png', 800, 600);
        $service = new ThumbnailService($this->cacheDir);
        $filename = (string) $service->thumbnailFilename($source, 'image/png');

        $path = $service->generatePending($filename);

        self::assertNotNull($path);
        self::assertSame(IMAGETYPE_JPEG, getimagesize($path)[2] ?? null);
    }

    private function jpeg(string $name, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 80, 40));
        $path = $this->dir . '/media/' . $name;
        imagejpeg($image, $path, 80);

        return $path;
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->rmrf($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}
