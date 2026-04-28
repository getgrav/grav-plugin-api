<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GpmController::class)]
class GpmControllerSecurityTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_gpm_security_' . uniqid();
        mkdir($this->tempDir . '/cache', 0775, true);
        mkdir($this->tempDir . '/plugins/api', 0775, true);
        file_put_contents($this->tempDir . '/README.md', 'root readme must not be exposed');
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    #[Test]
    public function readme_rejects_dot_dot_package_slug(): void
    {
        $user = TestHelper::createMockUser('auditor', [
            'access' => ['api' => ['access' => true, 'gpm' => ['read' => true]]],
        ]);

        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        TestHelper::createMockGrav([
            'config' => $config,
            'locator' => new GpmSecurityTestLocator($this->tempDir),
            'permissions' => new Permissions(),
        ]);

        $controller = new GpmController(\Grav\Common\Grav::instance(), $config);
        $request = TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/gpm/plugins/../readme',
            attributes: [
                'api_user' => $user,
                'route_params' => ['slug' => '..'],
            ],
        );

        $this->expectException(ValidationException::class);
        $controller->readme($request);
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
            if ($item === '.' || $item === '..') continue;
            $this->rmrf($path . '/' . $item);
        }
        rmdir($path);
    }
}

final class GpmSecurityTestLocator
{
    public function __construct(private readonly string $base) {}

    public function findResource(string $uri, bool $absolute = false, bool $createDir = false): string|false
    {
        if (str_starts_with($uri, 'cache://')) {
            return $this->base . '/cache';
        }

        if (str_starts_with($uri, 'user://')) {
            return rtrim($this->base . '/' . ltrim(substr($uri, strlen('user://')), '/'), '/');
        }

        return false;
    }
}
