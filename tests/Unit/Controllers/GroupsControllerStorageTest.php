<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Yaml;
use Grav\Plugin\Api\Controllers\GroupsController;
use Grav\Plugin\Api\Exceptions\ConflictException;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * show() read groups through Flex (the base user/config/groups.yaml) while
 * update()/delete() checked the merged `groups` config, so a group show()
 * found could 404 on update/delete, and an env-overlay-only group could be
 * baked into the base file on the next save. Every path now reads the base
 * file, the same one Flex stores to and saveGroupRow() writes.
 *
 * Writes take the lock core's SimpleStorage takes for the same file and read
 * the file again under it, so a group saved meanwhile is not undone.
 */
#[CoversClass(GroupsController::class)]
class GroupsControllerStorageTest extends TestCase
{
    private ?string $tmp = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/grav-groups-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/user/config', 0777, true);
        file_put_contents(
            $this->tmp . '/user/config/groups.yaml',
            Yaml::dump(['editors' => ['groupname' => 'editors', 'enabled' => true, 'access' => []]]),
        );
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            @unlink($this->tmp . '/user/config/groups.yaml');
            rmdir($this->tmp . '/user/config');
            rmdir($this->tmp . '/user');
            rmdir($this->tmp);
        }
        Grav::resetInstance();
    }

    #[Test]
    public function group_in_the_base_file_but_not_in_config_can_be_deleted(): void
    {
        // Config knows nothing about `editors` (a stale or overlay-built config); the
        // base file, which Flex and show() read, does.
        $controller = $this->controller(['groups' => []]);

        $response = $controller->delete($this->request('DELETE', 'editors'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], Yaml::parse((string) file_get_contents($this->tmp . '/user/config/groups.yaml')));
    }

    #[Test]
    public function overlay_only_group_is_not_baked_into_the_base_file(): void
    {
        // `overlay` exists only in merged config (an env overlay). Deleting it
        // through the base file is a 404, and the base file is left alone.
        $controller = $this->controller(['groups' => [
            'editors' => ['groupname' => 'editors'],
            'overlay' => ['groupname' => 'overlay'],
        ]]);

        try {
            $controller->delete($this->request('DELETE', 'overlay'));
            self::fail('Expected NotFoundException');
        } catch (NotFoundException) {
            $base = Yaml::parse((string) file_get_contents($this->tmp . '/user/config/groups.yaml'));
            self::assertSame(['editors'], array_keys($base));
        }
    }

    #[Test]
    public function show_reads_the_same_base_file_without_flex(): void
    {
        $controller = $this->controller(['groups' => []]);

        $response = $controller->show($this->request('GET', 'editors'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function a_write_keeps_a_group_saved_since_the_request_read_the_file(): void
    {
        $controller = $this->controller(['groups' => []]);
        $file = $this->tmp . '/user/config/groups.yaml';

        // Another save (Flex or a second request) adds `authors` after this
        // request read the file, then this request writes `writers`.
        file_put_contents($file, Yaml::dump([
            'editors' => ['groupname' => 'editors', 'enabled' => true, 'access' => []],
            'authors' => ['groupname' => 'authors', 'enabled' => false, 'access' => []],
        ]));
        $this->saveRow($controller, 'writers', ['groupname' => 'writers', 'access' => []]);

        $stored = Yaml::parse((string) file_get_contents($file));
        self::assertSame(['editors', 'authors', 'writers'], array_keys($stored));
        self::assertFalse($stored['authors']['enabled']);
    }

    #[Test]
    public function create_refuses_a_group_that_appeared_meanwhile(): void
    {
        $controller = $this->controller(['groups' => []]);

        $this->expectException(ConflictException::class);
        $this->saveRow($controller, 'editors', ['groupname' => 'editors', 'access' => ['admin' => ['super' => true]]], true);
    }

    #[Test]
    public function the_write_takes_the_lock_file_core_takes_for_groups_yaml(): void
    {
        $controller = $this->controller(['groups' => []]);
        $file = $this->tmp . '/user/config/groups.yaml';
        // Core: SimpleStorage::lockFile($file->filename()).
        $lock = CACHE_DIR . 'compiled/files/' . md5($file) . '.lock';
        @unlink($lock);

        $controller->delete($this->request('DELETE', 'editors'));

        self::assertFileExists($lock);
        @unlink($lock);
    }

    private function saveRow(GroupsController $controller, string $name, ?array $entry, bool $create = false): void
    {
        $method = new \ReflectionMethod($controller, 'saveGroupRow');
        $method->invoke($controller, $name, $entry, $create);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function controller(array $config): GroupsController
    {
        Grav::resetInstance();
        $grav = Grav::instance();
        $root = $this->tmp;
        $grav['locator'] = new class ($root) {
            public function __construct(private readonly string $root) {}

            public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false
            {
                return match ($uri) {
                    'user://config' => $this->root . '/user/config',
                    'user://config/groups.yaml' => is_file($this->root . '/user/config/groups.yaml') ? $this->root . '/user/config/groups.yaml' : false,
                    default => false,
                };
            }
        };
        $grav['cache'] = new class {
            public function clearCache(string $remove = 'standard'): array
            {
                return [];
            }
        };

        return new GroupsController($grav, new Config($config));
    }

    private function request(string $method, string $name): \Psr\Http\Message\ServerRequestInterface
    {
        return TestHelper::createMockRequest($method, '/groups/' . $name, attributes: [
            'api_user' => TestHelper::createMockUser('root', ['access' => ['api' => ['super' => true]]]),
            'route_params' => ['name' => $name],
        ]);
    }
}
