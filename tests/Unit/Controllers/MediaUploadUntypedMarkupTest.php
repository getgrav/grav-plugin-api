<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\BlueprintUploadController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * A file under an extension the site has no type for is sent by Apache and
 * Caddy with no Content-Type, and browsers read markup in it as a page.
 */
final class MediaUploadUntypedMarkupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/api-untyped-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public static function refused(): array
    {
        $html = "<html><body><script>alert(1)</script></body></html>";
        $cases = [];
        foreach ([MediaController::class, BlueprintUploadController::class] as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $cases["$short unknown extension"] = [$class, 'page.zzz', $html];
            $cases["$short leading whitespace"] = [$class, 'page.zzz', "\xEF\xBB\xBF \n\t" . $html];
            $cases["$short xml prolog"] = [$class, 'page.foo', '<?xml version="1.0"?><html xmlns="http://www.w3.org/1999/xhtml"/>'];
            $cases["$short two unknown extensions"] = [$class, 'page.zzz.bak', $html];
            $cases["$short defaults block"] = [$class, 'page.defaults', $html];
        }
        return $cases;
    }

    public static function accepted(): array
    {
        $cases = [];
        foreach ([MediaController::class, BlueprintUploadController::class] as $class) {
            $short = substr($class, strrpos($class, '\\') + 1);
            $cases["$short known type with markup"] = [$class, 'notes.txt', '<b>not a page</b>'];
            $cases["$short unknown type without markup"] = [$class, 'data.bin', "\x00\x01binary"];
            $cases["$short site adds the type"] = [$class, 'track.gpx', '<?xml version="1.0"?><gpx/>', ['gpx' => ['type' => 'file', 'mime' => 'application/gpx+xml']]];
        }
        return $cases;
    }

    #[DataProvider('refused')]
    public function testMarkupUnderAnUntypedExtensionIsRefused(string $class, string $name, string $bytes): void
    {
        try {
            $this->upload($class, $name, $bytes);
            self::fail("Expected '{$name}' to be refused.");
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getStatusCode());
        }
        self::assertFileDoesNotExist($this->dir . '/' . $name);
    }

    #[DataProvider('accepted')]
    public function testOtherUploadsAreKept(string $class, string $name, string $bytes, array $types = []): void
    {
        $this->upload($class, $name, $bytes, $types);
        self::assertSame($bytes, file_get_contents($this->dir . '/' . $name));
    }

    private function upload(string $class, string $name, string $bytes, array $types = []): void
    {
        $config = new Config([
            'media' => ['types' => $types + [
                'defaults' => ['type' => 'file', 'mime' => 'application/octet-stream'],
                'txt' => ['type' => 'file', 'mime' => 'text/plain'],
            ]],
            'security' => ['uploads_dangerous_extensions' => ['php', 'html', 'htm', 'svgz', 'xml']],
        ]);
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $file = new class ($name, $bytes) implements UploadedFileInterface {
            public function __construct(private string $name, private string $bytes) {}
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getSize(): ?int { return strlen($this->bytes); }
            public function getClientFilename(): ?string { return $this->name; }
            public function getClientMediaType(): ?string { return 'application/octet-stream'; }
            public function getStream(): StreamInterface { throw new \LogicException('Unused.'); }
            public function moveTo(string $path): void { file_put_contents($path, $this->bytes); }
        };

        $method = new \ReflectionMethod($class, 'processUploadedFile');
        $args = $class === MediaController::class ? [$file, $this->dir] : [$file, $this->dir, false];
        $method->invoke(new $class($grav, $config), ...$args);
    }
}
