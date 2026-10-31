<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Security;
use Grav\Plugin\Api\Controllers\BlueprintUploadController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MediaUploadSanitizerFailureTest extends TestCase
{
    public static function failures(): array
    {
        return [
            [MediaController::class, \RuntimeException::class],
            [MediaController::class, \ValueError::class],
            [BlueprintUploadController::class, \RuntimeException::class],
            [BlueprintUploadController::class, \ValueError::class],
        ];
    }

    public function testCoreSvgDetectionIsReusedForInnerExtensions(): void
    {
        class_alias(FailingSvgSecurity::class, Security::class);
        $path = tempnam(sys_get_temp_dir(), 'api-svg-detection-');
        file_put_contents($path, '<root/>');
        try {
            $controller = (new \ReflectionClass(MediaController::class))->newInstanceWithoutConstructor();
            self::assertTrue((new \ReflectionMethod($controller, 'isSvgUpload'))->invoke($controller, $path, 'drawing.svg.defaults'));
            self::assertSame([['drawing.svg.defaults', $path]], FailingSvgSecurity::$checked);
        } finally {
            unlink($path);
        }
    }

    #[DataProvider('failures')]
    public function testSanitizerFailurePreservesTheExistingPublicFile(string $class, string $failure): void
    {
        class_alias(FailingSvgSecurity::class, Security::class);
        FailingSvgSecurity::$failure = new $failure('Unable to sanitize.');
        $dir = sys_get_temp_dir() . '/api-svg-failure-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/logo.svg', 'existing');
        $config = new Config();
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $file = new class implements UploadedFileInterface {
            public array $paths = [];
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getSize(): ?int { return 40; }
            public function getClientFilename(): ?string { return 'logo.svg'; }
            public function getClientMediaType(): ?string { return 'image/svg+xml'; }
            public function getStream(): StreamInterface { throw new \LogicException('Unused.'); }
            public function moveTo(string $path): void { $this->paths[] = $path; file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"/>'); }
        };
        try {
            try {
                $method = new \ReflectionMethod($class, 'processUploadedFile');
                $args = $class === MediaController::class ? [$file, $dir] : [$file, $dir, false];
                $method->invoke(new $class($grav, $config), ...$args);
                self::fail('Expected a validation error.');
            } catch (ValidationException $e) {
                self::assertSame(422, $e->getStatusCode());
            } finally {
                self::assertSame('existing', file_get_contents($dir . '/logo.svg'));
            }
            foreach ($file->paths as $path) self::assertFileDoesNotExist($path);
        } finally {
            foreach ($file->paths as $path) if (is_file($path)) unlink($path);
            if (is_file($dir . '/logo.svg')) unlink($dir . '/logo.svg');
            rmdir($dir);
        }
    }
}

final class FailingSvgSecurity
{
    public static \Throwable $failure;
    public static array $checked = [];
    public static function isSvgFile(string $filename, string $path): bool
    {
        self::$checked[] = [$filename, $path];
        return true;
    }
    public static function sanitizeSVG(string $path): void { throw self::$failure; }
}
