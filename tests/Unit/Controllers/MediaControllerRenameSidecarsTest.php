<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MediaControllerRenameSidecarsTest extends TestCase
{
    public static function reservedNames(): array
    {
        return [
            ['photo.jpg.meta.yaml', 'settings.yaml'],
            ['media_order.yaml', 'settings.yaml'],
            ['ordinary.yaml', 'photo.jpg.meta.yaml'],
            ['ordinary.yaml', 'media_order.yaml'],
            ['ordinary.yaml', 'PHOTO.JPG.META.YAML'],
        ];
    }

    #[DataProvider('reservedNames')]
    public function testSidecarNamesAreRefusedAsSourceOrTarget(string $from, string $to): void
    {
        $dir = sys_get_temp_dir() . '/api-media-rename-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/' . $from, 'title: Example');
        $locator = new class ($dir) {
            public function __construct(private string $dir) {}
            public function findResource(...$args): string { return $this->dir; }
        };
        $config = new Config();
        $grav = TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]);
        $user = TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true, 'media' => ['write' => true]]]]);
        try {
            try {
                (new MediaController($grav, $config))->renameFile(TestHelper::createMockRequest(
                    method: 'POST', body: json_encode(['from' => $from, 'to' => $to]), attributes: ['api_user' => $user],
                ));
                self::fail('Expected a validation response.');
            } catch (ValidationException $e) {
                self::assertSame(422, $e->getStatusCode());
            } finally {
                self::assertFileExists($dir . '/' . $from);
                self::assertFileDoesNotExist($dir . '/' . $to);
            }
        } finally {
            foreach (glob($dir . '/*') as $file) unlink($file);
            rmdir($dir);
        }
    }
}
