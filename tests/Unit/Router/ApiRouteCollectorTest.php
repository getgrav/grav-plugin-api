<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Router;

use FastRoute\DataGenerator;
use FastRoute\RouteCollector;
use FastRoute\RouteParser;
use Grav\Plugin\Api\ApiRouteCollector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiRouteCollector::class)]
class ApiRouteCollectorTest extends TestCase
{
    #[Test]
    public function get_registers_get_route(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with('GET', '/items', ['ItemController', 'index']);

        $api = new ApiRouteCollector($collector);
        $result = $api->get('/items', ['ItemController', 'index']);

        self::assertSame($api, $result, 'get() should return $this for fluent chaining');
    }

    #[Test]
    public function post_registers_post_route(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with('POST', '/items', ['ItemController', 'create']);

        $api = new ApiRouteCollector($collector);
        $api->post('/items', ['ItemController', 'create']);
    }

    #[Test]
    public function patch_registers_patch_route(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with('PATCH', '/items/{id}', ['ItemController', 'update']);

        $api = new ApiRouteCollector($collector);
        $api->patch('/items/{id}', ['ItemController', 'update']);
    }

    #[Test]
    public function delete_registers_delete_route(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with('DELETE', '/items/{id}', ['ItemController', 'destroy']);

        $api = new ApiRouteCollector($collector);
        $api->delete('/items/{id}', ['ItemController', 'destroy']);
    }

    #[Test]
    public function put_registers_put_route(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with('PUT', '/items/{id}', ['ItemController', 'replace']);

        $api = new ApiRouteCollector($collector);
        $api->put('/items/{id}', ['ItemController', 'replace']);
    }

    #[Test]
    public function add_route_with_multiple_methods(): void
    {
        $collector = $this->createMock(RouteCollector::class);
        $collector->expects(self::once())
            ->method('addRoute')
            ->with(['GET', 'POST'], '/multi', ['MultiController', 'handle']);

        $api = new ApiRouteCollector($collector);
        $api->addRoute(['GET', 'POST'], '/multi', ['MultiController', 'handle']);
    }

    #[Test]
    public function group_prefixes_routes(): void
    {
        $collector = $this->createMock(RouteCollector::class);

        $matcher = self::exactly(2);
        $collector->expects($matcher)
            ->method('addRoute')
            ->willReturnCallback(function (string $method, string $route, array $handler) use ($matcher): void {
                match ($matcher->numberOfInvocations()) {
                    1 => self::assertSame([
                        'GET', '/admin/users', ['UserController', 'index'],
                    ], [$method, $route, $handler]),
                    2 => self::assertSame([
                        'POST', '/admin/users', ['UserController', 'create'],
                    ], [$method, $route, $handler]),
                };
            });

        $api = new ApiRouteCollector($collector);
        $api->group('/admin', function (ApiRouteCollector $group): void {
            $group->get('/users', ['UserController', 'index']);
            $group->post('/users', ['UserController', 'create']);
        });
    }

    #[Test]
    public function nested_groups(): void
    {
        $collector = $this->createMock(RouteCollector::class);

        $collector->expects(self::once())
            ->method('addRoute')
            ->with('GET', '/api/v2/pages', ['PageController', 'index']);

        $api = new ApiRouteCollector($collector);
        $api->group('/api', function (ApiRouteCollector $group): void {
            $group->group('/v2', function (ApiRouteCollector $inner): void {
                $inner->get('/pages', ['PageController', 'index']);
            });
        });
    }

    #[Test]
    public function group_returns_self_for_chaining(): void
    {
        $collector = $this->createMock(RouteCollector::class);

        $api = new ApiRouteCollector($collector);
        $result = $api->group('/prefix', function (ApiRouteCollector $group): void {
            // no-op
        });

        self::assertSame($api, $result, 'group() should return the outer collector for chaining');
    }
}
