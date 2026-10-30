<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Plugin\Api\Services\ThumbnailService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Browsers turn a photo upright from its EXIF Orientation tag when they show
 * the original, so the thumbnail has to be turned the same way: GD drops the
 * tag when it re-encodes (#68).
 *
 * Each source is stored landscape with a red block in its top-left corner.
 * Where that block ends up, plus whether width and height swapped, tells the
 * eight orientations apart.
 */
#[CoversClass(ThumbnailService::class)]
#[RequiresPhpExtension('exif')]
class ThumbnailServiceOrientationTest extends TestCase
{
    private string $dir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-thumb-orientation-' . bin2hex(random_bytes(4));
        $this->cacheDir = $this->dir . '/cache/api/thumbnails';
        mkdir($this->dir . '/media', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->cacheDir . '/*') ?: [], ...glob($this->dir . '/media/*') ?: []] as $file) {
            @unlink($file);
        }
        @rmdir($this->cacheDir);
        @rmdir($this->dir . '/cache/api');
        @rmdir($this->dir . '/cache');
        @rmdir($this->dir . '/media');
        @rmdir($this->dir);
    }

    /**
     * @return array<string, array{0: int, 1: bool, 2: string}>
     */
    public static function orientations(): array
    {
        // orientation => [width and height swap, corner the red block shows in]
        return [
            '1 upright' => [1, false, 'top-left'],
            '2 mirrored' => [2, false, 'top-right'],
            '3 upside down' => [3, false, 'bottom-right'],
            '4 flipped' => [4, false, 'bottom-left'],
            '5 transposed' => [5, true, 'top-left'],
            '6 turned right' => [6, true, 'top-right'],
            '7 transversed' => [7, true, 'bottom-right'],
            '8 turned left' => [8, true, 'bottom-left'],
        ];
    }

    #[Test]
    #[DataProvider('orientations')]
    public function a_resized_thumbnail_is_turned_upright(int $orientation, bool $swapped, string $corner): void
    {
        $source = $this->photo('photo.jpg', 1200, 800, $orientation);
        $service = new ThumbnailService($this->cacheDir, 300);

        $path = $service->getThumbnail($source);

        self::assertNotNull($path);
        self::assertSame($swapped ? [200, 300] : [300, 200], array_slice(getimagesize($path) ?: [], 0, 2));
        self::assertSame($corner, $this->redCorner($path));
    }

    #[Test]
    #[DataProvider('orientations')]
    public function a_photo_already_small_enough_is_turned_upright_too(int $orientation, bool $swapped, string $corner): void
    {
        $source = $this->photo('small.jpg', 240, 160, $orientation);
        $service = new ThumbnailService($this->cacheDir, 500);

        $path = $service->getThumbnail($source);

        self::assertNotNull($path);
        self::assertSame($swapped ? [160, 240] : [240, 160], array_slice(getimagesize($path) ?: [], 0, 2));
        self::assertSame($corner, $this->redCorner($path));
    }

    #[Test]
    public function the_deferred_path_turns_it_upright(): void
    {
        $source = $this->photo('photo.jpg', 1200, 800, 6);
        $service = new ThumbnailService($this->cacheDir, 300);

        $path = $service->generatePending((string) $service->thumbnailFilename($source, 'image/jpeg'));

        self::assertNotNull($path);
        self::assertSame([200, 300], array_slice(getimagesize($path) ?: [], 0, 2));
        self::assertSame('top-right', $this->redCorner($path));
    }

    #[Test]
    public function a_thumbnail_cached_before_orientation_was_applied_is_not_served(): void
    {
        // The thumbnail route tells browsers to keep a file for a year, and a
        // standard cache clear leaves cache://api alone, so a sideways
        // thumbnail cached by an older version has to get a new name.
        $source = $this->photo('photo.jpg', 1200, 800, 6);
        $old = md5($source . '|' . filemtime($source) . '|300') . '.jpg';
        mkdir($this->cacheDir, 0775, true);
        copy($source, $this->cacheDir . '/' . $old);
        $service = new ThumbnailService($this->cacheDir, 300);

        self::assertNotSame($old, $service->ensureThumbnail($source, 'image/jpeg'));
        self::assertNotSame($old, $service->thumbnailFilename($source, 'image/jpeg'));
    }

    /**
     * A landscape JPEG with a red block in its stored top-left corner, carrying
     * the given EXIF Orientation tag.
     */
    private function photo(string $name, int $width, int $height, int $orientation): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, intdiv($width, 4), intdiv($height, 4), imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string) ob_get_clean();

        // Minimal big-endian TIFF block with one IFD entry: Orientation (0x0112), SHORT.
        $tiff = "MM\x00\x2A" . pack('N', 8) . pack('n', 1)
            . pack('nnN', 0x0112, 3, 1) . pack('n', $orientation) . "\x00\x00"
            . pack('N', 0);
        $app1 = "\xFF\xE1" . pack('n', 2 + 6 + strlen($tiff)) . "Exif\x00\x00" . $tiff;

        $path = $this->dir . '/media/' . $name;
        file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));
        self::assertSame($orientation, (int) (@exif_read_data($path)['Orientation'] ?? 0), 'fixture carries the tag');

        return $path;
    }

    private function redCorner(string $path): ?string
    {
        $image = imagecreatefromjpeg($path);
        $width = imagesx($image);
        $height = imagesy($image);
        $corners = [
            'top-left' => [5, 5],
            'top-right' => [$width - 6, 5],
            'bottom-left' => [5, $height - 6],
            'bottom-right' => [$width - 6, $height - 6],
        ];
        $red = [];
        foreach ($corners as $name => [$x, $y]) {
            $rgb = imagecolorat($image, $x, $y);
            if ((($rgb >> 16) & 0xFF) > 200 && (($rgb >> 8) & 0xFF) < 80 && ($rgb & 0xFF) < 80) {
                $red[] = $name;
            }
        }

        return count($red) === 1 ? $red[0] : null;
    }
}
