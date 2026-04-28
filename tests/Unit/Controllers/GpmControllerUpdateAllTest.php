<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\GPM\GPM;
use Grav\Plugin\Api\Controllers\GpmController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Unit tests for GpmController::updateAll().
 *
 * These tests focus on the dependency-resolution and ordering behavior added
 * to the bulk-update flow. The real GPM and GpmService both touch the
 * filesystem and remote GPM repository; the controller exposes
 * getGpm() / installPackage() / updatePackage() as protected methods so a
 * test subclass can inject mocks for these collaborators.
 *
 * Each test uses a fresh GPM mock per call to getGpm(); cascade-skip
 * behavior is asserted via the controller's internal cascadedDeps tracking,
 * not via per-iteration changes to GPM::isUpdatable() (Grav core's
 * Remote\Packages static cache makes that mutate-and-recheck unreliable).
 */
#[CoversClass(GpmController::class)]
class GpmControllerUpdateAllTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_updateall_test_' . uniqid();
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeRequest(): ServerRequestInterface
    {
        $superAdmin = TestHelper::createMockUser('admin', [
            'access.api.super' => true,
        ]);

        return TestHelper::createMockRequest(
            method: 'POST',
            path: '/api/v1/gpm/update-all',
            headers: ['Content-Type' => 'application/json'],
            body: '{}',
            attributes: [
                'api_user' => $superAdmin,
                'json_body' => [],
            ],
        );
    }

    /**
     * Build a controller whose getGpm/installPackage/updatePackage are
     * driven by the supplied callables.
     *
     * @param callable():GPM $gpmFactory          Returns a GPM mock per call (allows per-iteration state)
     * @param callable(string,array):(string|bool) $installer Records and returns install results
     * @param callable(string,array):(string|bool) $updater   Records and returns update results
     */
    private function createController(
        callable $gpmFactory,
        callable $installer,
        callable $updater,
    ): GpmController {
        $tempDir = $this->tempDir;

        $config = new Config([
            'plugins' => ['api' => [
                'route' => '/api',
                'version_prefix' => 'v1',
            ]],
        ]);

        $locator = new class ($tempDir) {
            public function __construct(private string $base) {}
            public function findResource(string $uri, bool $absolute = false, bool $createDir = false): ?string
            {
                if (str_starts_with($uri, 'cache://')) {
                    return $this->base . '/cache';
                }
                return $this->base;
            }
        };

        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'locator' => $locator,
            'permissions' => new \stdClass(), // unused: super-admin shortcut bypasses resolver
        ]);

        return new class ($grav, $config, $gpmFactory, $installer, $updater) extends GpmController {
            /** @var callable():GPM */
            private $gpmFactory;
            /** @var callable(string,array):(string|bool) */
            private $installer;
            /** @var callable(string,array):(string|bool) */
            private $updater;

            public function __construct($grav, $config, callable $gpmFactory, callable $installer, callable $updater)
            {
                parent::__construct($grav, $config);
                $this->gpmFactory = $gpmFactory;
                $this->installer = $installer;
                $this->updater = $updater;
            }

            protected function getGpm(bool $refresh = false): GPM
            {
                return ($this->gpmFactory)();
            }

            protected function installPackage(string $slug, array $options): string|bool
            {
                return ($this->installer)($slug, $options);
            }

            protected function updatePackage(string $slug, array $options): string|bool
            {
                return ($this->updater)($slug, $options);
            }
        };
    }

    /**
     * Build a GPM mock with canned answers for the methods updateAll calls.
     *
     * @param array{plugins?: array<string,object>, themes?: array<string,object>} $updatable
     * @param array<string,bool> $isUpdatable map of slug -> bool
     * @param array<string,array<string,string>>|array<string,\Throwable> $depsBySlug
     */
    private function makeGpmMock(array $updatable, array $isUpdatable, array $depsBySlug): GPM
    {
        $gpm = $this->createMock(GPM::class);
        $gpm->method('getUpdatable')->willReturn($updatable);
        $gpm->method('isUpdatable')->willReturnCallback(
            fn (string $slug) => $isUpdatable[$slug] ?? false
        );
        $gpm->method('checkPackagesCanBeInstalled')->willReturnCallback(
            function (array $slugs) use ($depsBySlug): void {
                foreach ($slugs as $slug) {
                    if (($depsBySlug[$slug] ?? null) instanceof \Throwable) {
                        throw $depsBySlug[$slug];
                    }
                }
            }
        );
        $gpm->method('getDependencies')->willReturnCallback(
            function (array $slugs) use ($depsBySlug): array {
                $result = [];
                foreach ($slugs as $slug) {
                    $deps = $depsBySlug[$slug] ?? [];
                    if ($deps instanceof \Throwable) {
                        throw $deps;
                    }
                    foreach ($deps as $depSlug => $action) {
                        $result[$depSlug] = $action;
                    }
                }
                return $result;
            }
        );
        return $gpm;
    }

    private function decode(\Psr\Http\Message\ResponseInterface $response): array
    {
        // ApiResponse::create wraps the payload in a `data` envelope.
        $body = json_decode((string) $response->getBody(), true);
        return $body['data'] ?? $body;
    }

    // -------------------------------------------------------
    // Tests
    // -------------------------------------------------------

    #[Test]
    public function fails_package_when_grav_dep_not_satisfied(): void
    {
        // Two plugins updatable; "needy" requires a newer Grav, "ok" has no deps.
        $gravError = new \RuntimeException(
            '<red>One of the packages require Grav >=2.0.0-beta.2. Please update Grav to the latest release.'
        );
        $depsBySlug = [
            'needy' => $gravError, // throws when getDependencies(['needy']) is called
            'ok'    => [],
        ];

        $factory = function () use ($depsBySlug): GPM {
            return $this->makeGpmMock(
                updatable: ['plugins' => ['needy' => (object) [], 'ok' => (object) []]],
                isUpdatable: ['needy' => true, 'ok' => true],
                depsBySlug: $depsBySlug,
            );
        };

        $installCalls = [];
        $updateCalls = [];
        $controller = $this->createController(
            gpmFactory: $factory,
            installer: function (string $slug, array $opts) use (&$installCalls): bool {
                $installCalls[] = [$slug, $opts];
                return true;
            },
            updater: function (string $slug, array $opts) use (&$updateCalls): bool {
                $updateCalls[] = [$slug, $opts];
                return true;
            },
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        // 'needy' must NOT have been updated.
        $updatedSlugs = array_column($updateCalls, 0);
        $this->assertNotContains('needy', $updatedSlugs);
        $this->assertContains('ok', $updatedSlugs);

        $this->assertSame(['ok'], $body['updated']);
        $this->assertCount(1, $body['failed']);
        $this->assertSame('needy', $body['failed'][0]['package']);
        // Color tags should be stripped; Grav-required language preserved.
        $this->assertStringNotContainsString('<red>', $body['failed'][0]['error']);
        $this->assertStringContainsString('Grav >=2.0.0-beta.2', $body['failed'][0]['error']);
    }

    #[Test]
    public function cascades_dependency_update_before_target(): void
    {
        // 'parent' depends on 'child' needing an update.
        // Both appear in the initial updatable list. Processing parent first
        // cascade-installs child; when the loop reaches child it is found in
        // the cascadedDeps set and reported as skipped.
        $factory = function (): GPM {
            return $this->makeGpmMock(
                updatable: ['plugins' => ['parent' => (object) [], 'child' => (object) []]],
                isUpdatable: ['parent' => true, 'child' => true],
                depsBySlug: [
                    'parent' => ['child' => 'update'],
                    'child' => [],
                ],
            );
        };

        $callOrder = [];
        $controller = $this->createController(
            gpmFactory: $factory,
            installer: function (string $slug) use (&$callOrder): bool {
                $callOrder[] = "install:$slug";
                return true;
            },
            updater: function (string $slug) use (&$callOrder): bool {
                $callOrder[] = "update:$slug";
                return true;
            },
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        // child must be installed BEFORE parent is updated.
        $this->assertSame(['install:child', 'update:parent'], $callOrder);
        $this->assertSame(['parent'], $body['updated']);
        $this->assertSame(['child'], $body['cascaded_dependencies']);

        // child appears in the original updatable list, but on its iteration
        // the cascadedDeps set causes it to be skipped, not updated again.
        $this->assertCount(1, $body['skipped']);
        $this->assertSame('child', $body['skipped'][0]['package']);
        $this->assertSame([], $body['failed']);
    }

    #[Test]
    public function fails_package_when_dependency_install_throws(): void
    {
        $factory = function (): GPM {
            return $this->makeGpmMock(
                updatable: ['plugins' => ['parent' => (object) []]],
                isUpdatable: ['parent' => true],
                depsBySlug: ['parent' => ['child' => 'install']],
            );
        };

        $updateCalls = [];
        $controller = $this->createController(
            gpmFactory: $factory,
            installer: function (string $slug): never {
                throw new \RuntimeException("network error fetching $slug");
            },
            updater: function (string $slug, array $opts) use (&$updateCalls): bool {
                $updateCalls[] = $slug;
                return true;
            },
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        // updatePackage must NOT be invoked when a dep install fails.
        $this->assertSame([], $updateCalls);
        $this->assertSame([], $body['updated']);
        $this->assertCount(1, $body['failed']);
        $this->assertSame('parent', $body['failed'][0]['package']);
        $this->assertStringContainsString("Failed to install dependency 'child'", $body['failed'][0]['error']);
        $this->assertStringContainsString('network error fetching child', $body['failed'][0]['error']);
    }

    #[Test]
    public function passes_isTheme_option_for_theme_packages(): void
    {
        $factory = function (): GPM {
            return $this->makeGpmMock(
                updatable: [
                    'plugins' => ['p1' => (object) []],
                    'themes' => ['t1' => (object) []],
                ],
                isUpdatable: ['p1' => true, 't1' => true],
                depsBySlug: ['p1' => [], 't1' => []],
            );
        };

        $updateCalls = [];
        $controller = $this->createController(
            gpmFactory: $factory,
            installer: fn () => true,
            updater: function (string $slug, array $opts) use (&$updateCalls): bool {
                $updateCalls[$slug] = $opts;
                return true;
            },
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        $this->assertSame(['p1', 't1'], $body['updated']);
        $this->assertFalse($updateCalls['p1']['theme']);
        $this->assertTrue($updateCalls['t1']['theme']);
        // install_deps must be false: deps already resolved by the controller.
        $this->assertFalse($updateCalls['p1']['install_deps']);
        $this->assertFalse($updateCalls['t1']['install_deps']);
    }

    #[Test]
    public function reports_update_failure_when_service_returns_non_success(): void
    {
        $factory = function (): GPM {
            return $this->makeGpmMock(
                updatable: ['plugins' => ['boom' => (object) []]],
                isUpdatable: ['boom' => true],
                depsBySlug: ['boom' => []],
            );
        };

        $controller = $this->createController(
            gpmFactory: $factory,
            installer: fn () => true,
            updater: fn () => false, // service signaled failure (neither true nor string)
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        $this->assertSame([], $body['updated']);
        $this->assertCount(1, $body['failed']);
        $this->assertSame('boom', $body['failed'][0]['package']);
        $this->assertStringContainsString("Failed to update 'boom'", $body['failed'][0]['error']);
    }

    #[Test]
    public function returns_empty_buckets_when_nothing_updatable(): void
    {
        $factory = function (): GPM {
            return $this->makeGpmMock(
                updatable: ['plugins' => [], 'themes' => []],
                isUpdatable: [],
                depsBySlug: [],
            );
        };

        $controller = $this->createController(
            gpmFactory: $factory,
            installer: fn () => true,
            updater: fn () => true,
        );

        $response = $controller->updateAll($this->makeRequest());
        $body = $this->decode($response);

        $this->assertSame([], $body['updated']);
        $this->assertSame([], $body['failed']);
        $this->assertSame([], $body['skipped']);
        $this->assertSame([], $body['cascaded_dependencies']);
    }
}
