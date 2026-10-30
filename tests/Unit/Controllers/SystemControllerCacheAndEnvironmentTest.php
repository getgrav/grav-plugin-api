<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Config\Setup;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\SystemController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * DELETE /cache passed `images` / `assets` / `tmp` straight to core, which only
 * knows `images-only` / `assets-only` / `tmp-only` and quietly ran a standard
 * clear instead. DELETE /system/environments/{name} answered 422 for a missing
 * env and only needed api.config.write, though the folder can hold super-only
 * `system` / `security` overrides.
 */
#[CoversClass(SystemController::class)]
class SystemControllerCacheAndEnvironmentTest extends TestCase
{
    private ?string $tmp = null;
    private ?string $savedSetupEnv = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-syscache-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/env/staging/config', 0777, true);
        $this->savedSetupEnv = Setup::$environment;
        Setup::$environment = null;
    }

    protected function tearDown(): void
    {
        Setup::$environment = $this->savedSetupEnv;
        if ($this->tmp !== null && is_dir($this->tmp)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            ) as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmp);
        }
        Grav::resetInstance();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function scopes(): array
    {
        return [
            'all' => ['all', 'all'],
            'standard' => ['standard', 'standard'],
            'images' => ['images', 'images-only'],
            'assets' => ['assets', 'assets-only'],
            'tmp' => ['tmp', 'tmp-only'],
        ];
    }

    #[Test]
    #[DataProvider('scopes')]
    public function cache_scope_maps_to_the_core_argument(string $apiScope, string $coreScope): void
    {
        $cache = $this->recordingCache();
        [$controller] = $this->controller(['cache' => $cache]);

        $response = $controller->clearCache(TestHelper::createMockRequest(
            'DELETE',
            '/cache',
            queryParams: ['scope' => $apiScope],
            attributes: ['api_user' => $this->super()],
        ));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$coreScope], $cache->calls);
    }

    #[Test]
    public function unknown_cache_scope_is_rejected_before_clearing(): void
    {
        $cache = $this->recordingCache();
        [$controller] = $this->controller(['cache' => $cache]);

        try {
            $controller->clearCache(TestHelper::createMockRequest(
                'DELETE',
                '/cache',
                queryParams: ['scope' => 'images-only'],
                attributes: ['api_user' => $this->super()],
            ));
            self::fail('Expected ValidationException');
        } catch (ValidationException) {
            self::assertSame([], $cache->calls);
        }
    }

    #[Test]
    public function deleting_a_missing_environment_is_404(): void
    {
        [$controller] = $this->controller();

        $this->expectException(NotFoundException::class);
        $controller->deleteEnvironment($this->deleteRequest('nowhere', []));
    }

    #[Test]
    public function deleting_an_existing_environment_removes_it(): void
    {
        [$controller] = $this->controller();

        $response = $controller->deleteEnvironment($this->deleteRequest('staging', []));

        self::assertSame(204, $response->getStatusCode());
        self::assertDirectoryDoesNotExist($this->tmp . '/user/env/staging');
    }

    #[Test]
    public function config_write_scope_alone_cannot_delete_an_environment(): void
    {
        // A key capped to api.config.write (enough to create an env) must not
        // reach the delete, which now needs super.
        [$controller] = $this->controller();

        try {
            $controller->deleteEnvironment($this->deleteRequest('staging', ['api.config.write']));
            self::fail('Expected ForbiddenException');
        } catch (ForbiddenException) {
            self::assertDirectoryExists($this->tmp . '/user/env/staging');
        }
    }

    #[Test]
    public function any_admin_user_can_list_environments(): void
    {
        // Admin2 needs the list to reset a stale selection and to decide
        // whether to show its switcher, for editors too, not only for users
        // with api.system.read.
        [$controller] = $this->controller(['uri' => new class {
            public function environment(): string
            {
                return 'localhost';
            }
        }]);
        $editor = TestHelper::createMockUser('editor', ['access' => ['api' => ['access' => true]]]);

        $response = $controller->environments(
            TestHelper::createMockRequest('GET', '/system/environments', attributes: ['api_user' => $editor])
        );

        $body = json_decode((string)$response->getBody(), true);
        $names = array_column($body['data']['environments'] ?? $body['environments'] ?? [], 'name');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', 'staging'], $names);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function deleteRequest(string $name, array $scopes): \Psr\Http\Message\ServerRequestInterface
    {
        $attributes = ['api_user' => $this->super(), 'route_params' => ['name' => $name]];
        if ($scopes !== []) {
            $attributes['api_key_scopes'] = $scopes;
        }

        return TestHelper::createMockRequest('DELETE', '/system/environments/' . $name, attributes: $attributes);
    }

    /**
     * @param array<string, mixed> $services
     * @return array{SystemController}
     */
    private function controller(array $services = []): array
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $root = $this->tmp;
        $grav['locator'] = new class ($root) {
            public function __construct(private readonly string $root) {}

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                return $uri === 'user://' ? $this->root . '/user' : false;
            }
        };
        foreach ($services as $key => $value) {
            $grav[$key] = $value;
        }

        return [new SystemController($grav, new Config())];
    }

    private function recordingCache(): object
    {
        return new class {
            /** @var array<int, string> */
            public array $calls = [];

            /** @return array<int, string> */
            public function clearCache(string $remove = 'standard'): array
            {
                $this->calls[] = $remove;
                return [];
            }
        };
    }

    private function super(): UserInterface
    {
        return TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]);
    }
}
