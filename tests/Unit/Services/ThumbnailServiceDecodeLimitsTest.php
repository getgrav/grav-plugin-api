<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Page\Medium\ImageFile;
use Grav\Plugin\Api\Services\ThumbnailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thumbnails use the limits core checks before it decodes an image (Grav
 * 2.2.35+): a JPEG with more scans than ImageFile::MAX_JPEG_SCANS gets no
 * thumbnail, and the Exif orientation is read without exif_read_data(), which
 * a small crafted file makes take close to a gigabyte.
 */
#[CoversClass(ThumbnailService::class)]
class ThumbnailServiceDecodeLimitsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-thumb-limits-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->dir . '/cache/*') ?: [], ...glob($this->dir . '/*.jpg') ?: []] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir);
    }

    #[Test]
    public function a_jpeg_with_more_scans_than_core_allows_gets_no_thumbnail(): void
    {
        if (!method_exists(ImageFile::class, 'jpegScanCount')) {
            self::markTestSkipped('Needs Grav 2.2.35 or newer.');
        }

        $service = new ThumbnailService($this->dir . '/cache', 8);
        $many = $this->jpeg('many.jpg', $this->comment(str_repeat("\xFF\xDA\x00\x08", ImageFile::MAX_JPEG_SCANS + 1)));
        $few = $this->jpeg('few.jpg', $this->comment(str_repeat("\xFF\xDA\x00\x08", 3)));

        self::assertNull($service->getThumbnail($many));
        self::assertNotNull($service->getThumbnail($few));
    }

    #[Test]
    public function an_exif_block_with_a_thousand_entries_does_not_exhaust_memory(): void
    {
        if (!method_exists(ImageFile::class, 'exifOrientation')) {
            self::markTestSkipped('Needs Grav 2.2.35 or newer.');
        }

        // 999 entries that each claim 30,000 values: exif_read_data() takes
        // about 900 MB for this 60 KB file, core's reader a few bytes.
        $entries = '';
        for ($n = 0; $n < 999; $n++) {
            $entries .= pack('vvVV', 0xA500 + $n, 3, 30000, 8);
        }
        $tiff = str_pad("II*\0" . pack('V', 8) . pack('v', 999) . $entries . pack('V', 0), 8 + 60000, 'A');
        $source = $this->jpeg('bomb.jpg', "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff);

        $previous = ini_get('memory_limit');
        ini_set('memory_limit', (string) max(memory_get_usage(true) + 96 * 1048576, 128 * 1048576));
        try {
            $path = (new ThumbnailService($this->dir . '/cache', 8))->getThumbnail($source);
        } finally {
            ini_set('memory_limit', (string) $previous);
        }

        self::assertNotNull($path);
        self::assertSame([8, 8], array_slice(getimagesize($path) ?: [], 0, 2));
    }

    /** A 16x16 JPEG with $segment inserted right after its SOI marker. */
    private function jpeg(string $name, string $segment): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(16, 16));
        $jpeg = (string) ob_get_clean();

        $path = $this->dir . '/' . $name;
        file_put_contents($path, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));

        return $path;
    }

    /** A COM segment holding $text. */
    private function comment(string $text): string
    {
        return "\xFF\xFE" . pack('n', strlen($text) + 2) . $text;
    }
}
