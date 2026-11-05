<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\BlueprintController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GET /blueprints/users/permissions marks each action `grantable` for the
 * caller, so Admin2 can disable the Allow toggle on permissions a non-super
 * user manager doesn't hold (the API refuses those grants).
 */
#[CoversClass(BlueprintController::class)]
class BlueprintPermissionsGrantableTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /** @return array<string, bool> */
    private function grantable(UserInterface $caller): array
    {
        // The controller only iterates the registry and each action's children,
        // reading ->name and ->label, so a plain tree of nodes stands in for it.
        $node = static fn (string $name, array $children = []) => new class ($name, $children) implements \IteratorAggregate {
            public string $label;
            public function __construct(public string $name, private array $children) { $this->label = $name; }
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->children); }
        };
        $tree = [
            'api' => $node('api', [
                $node('api.access'),
                $node('api.users', [$node('api.users.read'), $node('api.users.write')]),
                $node('api.pages', [$node('api.pages.read'), $node('api.pages.write')]),
                $node('api.gpm', [$node('api.gpm.write')]),
            ]),
            'site' => $node('site', [$node('site.login')]),
        ];
        $permissions = new class ($tree) extends Permissions implements \IteratorAggregate {
            public function __construct(private array $tree) {}
            public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->tree); }
        };
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        TestHelper::createMockGrav([
            'config'      => $config,
            'permissions' => $permissions,
            // Label translation looks for language folders; none exist here.
            'locator'     => new class {
                public function findResource(string $uri, bool $absolute = true, bool $first = false): string|false { return false; }
                public function findResources(string $uri, bool $absolute = true): array { return []; }
            },
            'accounts'    => TestHelper::createMockAccounts([$caller->username => $caller]),
        ]);
        $controller = new BlueprintController(Grav::instance(), $config);

        $response = $controller->permissionsBlueprint(TestHelper::createMockRequest(
            method: 'GET',
            path: '/api/v1/blueprints/users/permissions',
            attributes: ['api_user' => $caller],
        ));
        $body = json_decode((string) $response->getBody(), true);
        $sections = $body['data'] ?? $body;

        $flat = [];
        $walk = function (array $nodes) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                $flat[$node['name']] = $node['grantable'];
                $walk($node['children'] ?? []);
            }
        };
        $walk($sections);

        return $flat;
    }

    #[Test]
    public function a_user_manager_can_grant_only_what_it_holds(): void
    {
        $manager = TestHelper::createMockUser('manager', [
            'access' => [
                'api' => ['access' => true, 'users' => ['read' => true, 'write' => true], 'pages' => ['read' => true]],
                'site' => ['login' => true],
            ],
        ]);

        $flat = $this->grantable($manager);

        $this->assertTrue($flat['api.access']);
        $this->assertTrue($flat['api.users.write']);
        $this->assertTrue($flat['api.pages.read']);
        $this->assertTrue($flat['site.login']);
        $this->assertFalse($flat['api.pages.write']);
        $this->assertFalse($flat['api.gpm.write']);
        $this->assertFalse($flat['api.pages']);
        $this->assertFalse($flat['api']);
    }

    #[Test]
    public function a_super_admin_can_grant_everything(): void
    {
        $super = TestHelper::createMockUser('admin', ['access' => ['api' => ['access' => true, 'super' => true]]]);

        $flat = $this->grantable($super);

        $this->assertNotEmpty($flat);
        $this->assertNotContains(false, $flat);
    }
}
