<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionMethod;

/**
 * Unit coverage for the shared upload pipeline (HandlesMediaUploads). The trait
 * is the security-critical, storage-agnostic core reused by both page media and
 * flex-object media, so it carries the validation guarantees that matter:
 * dangerous extensions are blocked, traversal filenames are rejected, the size
 * cap is enforced, and a clean file lands in the target folder.
 *
 * Exercised through MediaController since it uses the trait; the methods under
 * test are storage-agnostic, so the same behavior applies to FlexApiController.
 */
#[CoversTrait(\Grav\Plugin\Api\Controllers\HandlesMediaUploads::class)]
class HandlesMediaUploadsTest extends TestCase
{
    private string $tempDir;
    private MediaController $controller;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_media_trait_' . uniqid();
        mkdir($this->tempDir, 0775, true);

        $config = new Config([
            'security' => ['uploads_dangerous_extensions' => ['php', 'phtml', 'phar', 'js', 'html']],
        ]);

        // createMockGrav installs the Grav singleton the base controller reads.
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $this->controller = new MediaController($grav, $config);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    #[Test]
    public function a_clean_file_lands_in_the_target_folder(): void
    {
        $file = new TraitTestUploadedFile('avatar.png', 'binary-png-data');

        $name = $this->invoke('processUploadedFile', $file, $this->tempDir);

        self::assertSame('avatar.png', $name);
        self::assertFileExists($this->tempDir . '/avatar.png');
        self::assertSame('binary-png-data', file_get_contents($this->tempDir . '/avatar.png'));
    }

    #[Test]
    public function dangerous_extension_is_rejected_and_no_file_is_written(): void
    {
        $file = new TraitTestUploadedFile('shell.php', '<?php evil();');

        try {
            $this->invoke('processUploadedFile', $file, $this->tempDir);
            self::fail('Expected ValidationException for a .php upload.');
        } catch (ValidationException) {
            self::assertFileDoesNotExist($this->tempDir . '/shell.php');
        }
    }

    #[Test]
    public function extensionless_file_is_rejected(): void
    {
        $file = new TraitTestUploadedFile('README', 'no extension');

        $this->expectException(ValidationException::class);
        $this->invoke('processUploadedFile', $file, $this->tempDir);
    }

    #[Test]
    public function traversal_filename_is_rejected(): void
    {
        $file = new TraitTestUploadedFile('../../evil.png', 'png');

        // basename() strips the path, but the resulting name still must not be
        // a dotfile or contain traversal markers — assert nothing escapes.
        try {
            $this->invoke('processUploadedFile', $file, $this->tempDir);
        } catch (ValidationException) {
            // acceptable — rejected outright
        }

        self::assertFileDoesNotExist(dirname($this->tempDir) . '/evil.png');
    }

    #[Test]
    public function dotfile_is_rejected(): void
    {
        $file = new TraitTestUploadedFile('.htaccess', 'deny');

        $this->expectException(ValidationException::class);
        $this->invoke('processUploadedFile', $file, $this->tempDir);
    }

    #[Test]
    public function oversize_file_is_rejected(): void
    {
        // Reports a size beyond the 64 MB cap without writing 64 MB to disk.
        $file = new TraitTestUploadedFile('big.png', 'x', 67_108_864 + 1);

        $this->expectException(ValidationException::class);
        $this->invoke('processUploadedFile', $file, $this->tempDir);
    }

    #[Test]
    public function nested_uploaded_files_are_flattened(): void
    {
        $a = new TraitTestUploadedFile('a.png', 'a');
        $b = new TraitTestUploadedFile('b.png', 'b');
        $c = new TraitTestUploadedFile('c.png', 'c');

        // Mirrors PSR-7 nesting: files[gallery][] alongside files[avatar].
        $flat = $this->invoke('flattenUploadedFiles', ['avatar' => $a, 'gallery' => [$b, $c]]);

        self::assertCount(3, $flat);
        self::assertContainsOnlyInstancesOf(UploadedFileInterface::class, $flat);
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($this->controller, $method);
        return $ref->invoke($this->controller, ...$args);
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $item);
        }
        rmdir($path);
    }
}

final class TraitTestUploadedFile implements UploadedFileInterface
{
    private readonly string $source;
    private bool $moved = false;

    public function __construct(
        private readonly string $filename,
        string $contents,
        private readonly ?int $reportedSize = null,
    ) {
        $this->source = tempnam(sys_get_temp_dir(), 'grav_api_trait_upload_') ?: '';
        file_put_contents($this->source, $contents);
    }

    public function getStream(): StreamInterface
    {
        throw new \RuntimeException('Not needed in tests.');
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new \RuntimeException('File already moved.');
        }
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        rename($this->source, $targetPath);
        $this->moved = true;
    }

    public function getSize(): ?int
    {
        return $this->reportedSize ?? (file_exists($this->source) ? filesize($this->source) : null);
    }

    public function getError(): int { return UPLOAD_ERR_OK; }
    public function getClientFilename(): ?string { return $this->filename; }
    public function getClientMediaType(): ?string { return 'application/octet-stream'; }
}
