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
    private string|false $savedEnvironmentsPath = false;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-cfgctl-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/config', 0777, true);
        $this->savedEnvironmentsPath = getenv('GRAV_ENVIRONMENTS_PATH');
        putenv('GRAV_ENVIRONMENTS_PATH');
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            $this->rrmdir($this->tmp);
            $this->tmp = null;
        }
        if ($this->savedEnvironmentsPath === false) {
            putenv('GRAV_ENVIRONMENTS_PATH');
        } else {
            putenv('GRAV_ENVIRONMENTS_PATH=' . $this->savedEnvironmentsPath);
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
    public function default_sentinel_header_is_explicit_base_write(): void
    {
        // admin-next sends the reserved `default` sentinel for its base
        // ("Default") selection — non-empty so proxies/FPM can't strip it.
        // It must resolve to a base write even when a Grav env is active.
        mkdir($this->tmp . '/user/localhost/config', 0777, true);
        $controller = $this->buildController(activeEnv: 'localhost');

        $request = TestHelper::createMockRequest(
            'PATCH',
            '/config/system',
            ['X-Config-Environment' => 'default'],
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

    #[Test]
    public function controller_write_target_uses_gravs_custom_environment_path(): void
    {
        $customConfig = $this->tmp . '/user/custom-envs/staging/config';
        mkdir($customConfig, 0777, true);
        putenv('GRAV_ENVIRONMENTS_PATH=user://custom-envs');

        $controller = $this->buildController(activeEnv: 'localhost', environmentsRoot: $this->tmp . '/user/custom-envs');
        $ref = new \ReflectionMethod($controller, 'resolveWriteDir');

        $expected = $this->tmp . DIRECTORY_SEPARATOR . 'user' . DIRECTORY_SEPARATOR
            . 'custom-envs' . DIRECTORY_SEPARATOR . 'staging' . DIRECTORY_SEPARATOR . 'config';
        $normalize = static fn(string $path): string => str_replace('\\', '/', $path);
        $this->assertSame($normalize($expected), $normalize($ref->invoke($controller, 'staging')));
    }

    private function buildController(?string $activeEnv, ?string $environmentsRoot = null): ConfigController
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new CfgCtlFakeLocator($this->tmp, $environmentsRoot);
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
    public function __construct(private readonly string $root, private readonly ?string $environmentsRoot = null) {}

    public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
    {
        if ($uri === 'user://') {
            return is_dir($this->root . '/user') ? $this->root . '/user' : false;
        }
        if ($this->environmentsRoot !== null && str_starts_with($uri, 'user://custom-envs')) {
            $suffix = substr($uri, strlen('user://custom-envs'));
            $path = $this->environmentsRoot . str_replace('/', DIRECTORY_SEPARATOR, $suffix);
            return (file_exists($path) || $first) ? $path : false;
        }
        return false;
    }
}
