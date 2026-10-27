<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Site media paths must resolve inside the media folder. A symlink out to a
 * sibling whose name extends the base (`media2`) must still be refused after
 * the separator-agnostic containment check from #66.
 */
#[CoversClass(MediaController::class)]
class MediaControllerPathContainmentTest extends TestCase
{
    private string $root;
    private string $media;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/grav_api_media_contain_' . bin2hex(random_bytes(4));
        $this->media = $this->root . '/media';
        mkdir($this->media . '/photos', 0777, true);
        mkdir($this->root . '/media2', 0777, true);
        file_put_contents($this->media . '/photos/a.jpg', 'a');
        file_put_contents($this->root . '/media2/secret.jpg', 's');
        symlink($this->root . '/media2', $this->media . '/escape');

        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        TestHelper::createMockGrav(['config' => $config]);
    }

    protected function tearDown(): void
    {
        @unlink($this->media . '/escape');
        @unlink($this->media . '/photos/a.jpg');
        @unlink($this->root . '/media2/secret.jpg');
        @rmdir($this->media . '/photos');
        @rmdir($this->media);
        @rmdir($this->root . '/media2');
        @rmdir($this->root);
    }

    private function validate(string $path): string
    {
        $controller = new MediaController(Grav::instance(), Grav::instance()['config']);
        $method = new \ReflectionMethod($controller, 'validateRelativePath');

        return $method->invoke($controller, $path, $this->media);
    }

    #[Test]
    public function aFileInsideTheMediaFolderIsAccepted(): void
    {
        self::assertSame('photos/a.jpg', $this->validate('photos/a.jpg'));
        self::assertSame('photos', $this->validate('photos'));
        self::assertSame('photos/a.jpg', $this->validate('photos\\a.jpg'));
    }

    #[Test]
    public function aSymlinkIntoASiblingWithALongerNameIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->validate('escape/secret.jpg');
    }
}
