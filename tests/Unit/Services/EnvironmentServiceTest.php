<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Services;

use Grav\Common\Config\Setup;
use Grav\Common\Grav;
use Grav\Plugin\Api\Services\EnvironmentService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvironmentService::class)]
class EnvironmentServiceTest extends TestCase
{
    private ?string $tmp = null;
    private ?string $savedSetupEnv = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-envsvc-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/config', 0777, true);

        // activeEnvironment() now trusts Setup::$environment (the booted env)
        // before the Uri. Null it so the Uri-only tests are deterministic; the
        // booted-env tests set it explicitly. Restore the global afterwards.
        $this->savedSetupEnv = Setup::$environment;
        Setup::$environment = null;
    }

    protected function tearDown(): void
    {
        Setup::$environment = $this->savedSetupEnv;
        if ($this->tmp !== null) {
            $this->rrmdir($this->tmp);
            $this->tmp = null;
        }
        Grav::resetInstance();
    }

    #[Test]
    public function active_environment_returns_null_when_no_uri_service(): void
    {
        $svc = $this->buildService(uri: null);

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_returns_null_when_env_name_empty(): void
    {
        $svc = $this->buildService(uri: $this->fakeUri(''));

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_returns_null_when_env_name_invalid(): void
    {
        // Path traversal must never be honored as an env.
        $svc = $this->buildService(uri: $this->fakeUri('../etc'));

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_returns_null_when_env_config_dir_missing(): void
    {
        // Hostname-derived env that doesn't have a config folder on disk —
        // base writes are correct in this case, no overlay to worry about.
        $svc = $this->buildService(uri: $this->fakeUri('production.example.com'));

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_resolves_legacy_user_host_config_layout(): void
    {
        mkdir($this->tmp . '/user/localhost/config', 0777, true);
        $svc = $this->buildService(uri: $this->fakeUri('localhost'));

        $this->assertSame('localhost', $svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_resolves_modern_user_env_layout(): void
    {
        mkdir($this->tmp . '/user/env/staging/config', 0777, true);
        $svc = $this->buildService(uri: $this->fakeUri('staging'));

        $this->assertSame('staging', $svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_prefers_booted_env_behind_reverse_proxy(): void
    {
        // Reverse proxy: Grav booted its overlay from the real host (localhost,
        // which has a config dir), while the Uri reports the forwarded host
        // (translations.rhuk.net, no dir). The loaded overlay is localhost, so
        // that's what must be reported — not null, and not the forwarded host.
        mkdir($this->tmp . '/user/env/localhost/config', 0777, true);
        Setup::$environment = 'localhost';
        $svc = $this->buildService(uri: $this->fakeUri('translations.rhuk.net'));

        $this->assertSame('localhost', $svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_is_null_when_booted_env_has_no_config_dir(): void
    {
        // The booted env is authoritative: if IT has no overlay on disk, no
        // overlay is loaded and base is correct — we must NOT fall through to a
        // forwarded-host env that happens to have a dir but was never loaded.
        mkdir($this->tmp . '/user/env/staging/config', 0777, true);
        Setup::$environment = 'localhost'; // booted host, no config dir
        $svc = $this->buildService(uri: $this->fakeUri('staging'));

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_ignores_malformed_booted_env_and_falls_back_to_uri(): void
    {
        // A malformed Setup::$environment is treated as "unknown", so the Uri
        // fallback still applies.
        mkdir($this->tmp . '/user/env/staging/config', 0777, true);
        Setup::$environment = '../etc';
        $svc = $this->buildService(uri: $this->fakeUri('staging'));

        $this->assertSame('staging', $svc->activeEnvironment());
    }

    #[Test]
    public function active_environment_ignores_uri_without_environment_method(): void
    {
        // Some frameworks ship a Uri-ish object — only Grav's exposes environment().
        $svc = $this->buildService(uri: new \stdClass());

        $this->assertNull($svc->activeEnvironment());
    }

    #[Test]
    public function is_reserved_name_matches_base_sentinels_case_insensitively(): void
    {
        $this->assertTrue(EnvironmentService::isReservedName('default'));
        $this->assertTrue(EnvironmentService::isReservedName('Default'));
        $this->assertTrue(EnvironmentService::isReservedName('base'));
        $this->assertFalse(EnvironmentService::isReservedName('localhost'));
        $this->assertFalse(EnvironmentService::isReservedName('staging'));
    }

    #[Test]
    public function create_environment_rejects_reserved_base_sentinel(): void
    {
        // `default` must never become an env folder, otherwise its overlay would
        // shadow the admin's base-only ("Default") view.
        $svc = $this->buildService(uri: null);

        $this->expectException(\InvalidArgumentException::class);
        $svc->createEnvironment('default');
    }

    #[Test]
    public function create_environment_creates_modern_env_dir(): void
    {
        $svc = $this->buildService(uri: null);

        $dir = $svc->createEnvironment('staging');

        $this->assertSame($this->tmp . '/user/env/staging/config', $dir);
        $this->assertDirectoryExists($dir);
    }

    private function buildService(mixed $uri): EnvironmentService
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $grav['locator'] = new EnvSvcFakeLocator($this->tmp);
        if ($uri !== null) {
            $grav['uri'] = $uri;
        }
        return new EnvironmentService($grav);
    }

    private function fakeUri(string $env): object
    {
        return new class ($env) {
            public function __construct(private readonly string $env) {}
            public function environment(): string { return $this->env; }
        };
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
 * EnvironmentService only ever resolves `user://`, so a one-prefix locator
 * is enough — and intentionally minimal so each test reflects exactly what
 * the service walks.
 */
class EnvSvcFakeLocator
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
