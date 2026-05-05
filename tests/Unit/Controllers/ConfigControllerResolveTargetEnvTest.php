<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Plugin\Api\Controllers\ConfigController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * resolveTargetEnv() decides where a config write lands. The interesting
 * cases are the three header states, so we drive it directly via reflection
 * rather than wiring a full update() round-trip.
 */
#[CoversClass(ConfigController::class)]
class ConfigControllerResolveTargetEnvTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-cfgctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/config', 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            $this->rrmdir($this->tmp);
            $this->tmp = null;
        }
        Grav::resetInstance();
    }

    #[Test]
    public function missing_header_falls_back_to_active_environment(): void
    {
        // Simulates the bug we're fixing: hostname-derived env folder exists
        // and shadows base config, but admin2 doesn't pass X-Config-Environment.
        mkdir($this->tmp . '/user/localhost/config', 0777, true);
        $controller = $this->buildController(activeEnv: 'localhost');

        $request = TestHelper::createMockRequest('PATCH', '/config/system');

        $this->assertSame('localhost', $this->invokeResolveTargetEnv($controller, $request));
    }

    #[Test]
    public function missing_header_returns_null_when_no_active_env_overlay(): void
    {
        // Active env name is set but has no config dir — base writes are correct.
        $controller = $this->buildController(activeEnv: 'production.example.com');

        $request = TestHelper::createMockRequest('PATCH', '/config/system');

        $this->assertNull($this->invokeResolveTargetEnv($controller, $request));
    }

    #[Test]
    public function empty_header_is_explicit_base_write_and_skips_auto_detect(): void
    {
        // Caller wants to bypass auto-detection — they MUST be able to target
        // base even when a Grav env is active. An explicitly-empty header is
        // the opt-out lever.
        mkdir($this->tmp . '/user/localhost/config', 0777, true);
        $controller = $this->buildController(activeEnv: 'localhost');

        $request = TestHelper::createMockRequest(
            'PATCH',
            '/config/system',
            ['X-Config-Environment' => ''],
        );

        $this->assertNull($this->invokeResolveTargetEnv($controller, $request));
    }

    #[Test]
    public function explicit_header_value_wins_over_active_env(): void
    {
        mkdir($this->tmp . '/user/localhost/config', 0777, true);
        mkdir($this->tmp . '/user/env/staging/config', 0777, true);
        $controller = $this->buildController(activeEnv: 'localhost');

        $request = TestHelper::createMockRequest(
            'PATCH',
            '/config/system',
            ['X-Config-Environment' => 'staging'],
        );

        $this->assertSame('staging', $this->invokeResolveTargetEnv($controller, $request));
    }

    #[Test]
    public function invalid_header_value_throws(): void
    {
        $controller = $this->buildController(activeEnv: null);

        $request = TestHelper::createMockRequest(
            'PATCH',
            '/config/system',
            ['X-Config-Environment' => '../etc'],
        );

        $this->expectException(ValidationException::class);
        $this->invokeResolveTargetEnv($controller, $request);
    }

    private function buildController(?string $activeEnv): ConfigController
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new CfgCtlFakeLocator($this->tmp);
        if ($activeEnv !== null) {
            $grav['uri'] = new class ($activeEnv) {
                public function __construct(private readonly string $env) {}
                public function environment(): string { return $this->env; }
            };
        }
        return new ConfigController($grav, new Config());
    }

    private function invokeResolveTargetEnv(ConfigController $controller, object $request): ?string
    {
        $ref = new \ReflectionMethod($controller, 'resolveTargetEnv');
        return $ref->invoke($controller, $request);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}

/**
 * EnvironmentService only ever resolves user://, mirrored from the
 * EnvironmentServiceTest fixture so this test is self-contained.
 */
class CfgCtlFakeLocator
{
    public function __construct(private readonly string $root) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        if ($uri === 'user://') {
            return is_dir($this->root . '/user') ? $this->root . '/user' : false;
        }
        return false;
    }
}
