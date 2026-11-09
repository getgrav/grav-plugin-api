<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Router;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use Grav\Common\Config\Config;
use Grav\Plugin\Api\ApiRouter;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Demo\DemoPolicy;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function FastRoute\simpleDispatcher;

/**
 * A page whose route ends like a page sub-resource (`/ref/languages`,
 * `/blog/media`) must still be reachable as a page (getgrav/grav-plugin-admin2#213),
 * without taking the sub-resource away from its parent.
 */
final class PageRouteCollisionTest extends TestCase
{
    /** @return iterable<string, array{string, string, array{string, string}, array<string, string>}> */
    public static function explicitPageRequests(): iterable
    {
        yield 'GET nested languages page' => ['GET', '/pages/ref/languages', [PagesController::class, 'show'], ['route' => 'ref/languages']];
        yield 'GET nested media page' => ['GET', '/pages/blog/media', [PagesController::class, 'show'], ['route' => 'blog/media']];
        yield 'GET nested neighbors page' => ['GET', '/pages/a/neighbors', [PagesController::class, 'show'], ['route' => 'a/neighbors']];
        yield 'GET nested compare page' => ['GET', '/pages/a/compare', [PagesController::class, 'show'], ['route' => 'a/compare']];
        yield 'GET page under media/x/meta' => ['GET', '/pages/a/media/b/meta', [PagesController::class, 'show'], ['route' => 'a/media/b/meta']];
        yield 'PATCH page under media/x/meta' => ['PATCH', '/pages/a/media/b/meta', [PagesController::class, 'update'], ['route' => 'a/media/b/meta']];
        yield 'DELETE page under a media folder' => ['DELETE', '/pages/blog/media/press', [PagesController::class, 'delete'], ['route' => 'blog/media/press']];
        yield 'DELETE page under media/x/meta' => ['DELETE', '/pages/a/media/b/meta', [PagesController::class, 'delete'], ['route' => 'a/media/b/meta']];
    }

    #[Test]
    #[DataProvider('explicitPageRequests')]
    public function resource_page_reaches_the_page_itself(string $method, string $path, array $handler, array $vars): void
    {
        [$gotHandler, $gotVars] = $this->route($method, $path, ['resource' => 'page']);

        self::assertSame($handler, $gotHandler);
        self::assertSame($vars, $gotVars);
    }

    #[Test]
    public function sub_resources_keep_their_routes_without_the_flag(): void
    {
        self::assertSame([PagesController::class, 'languages'], $this->route('GET', '/pages/ref/languages')[0]);
        self::assertSame([MediaController::class, 'pageMedia'], $this->route('GET', '/pages/blog/media')[0]);
        self::assertSame([MediaController::class, 'deletePageMedia'], $this->route('DELETE', '/pages/blog/media/a.jpg')[0]);
        self::assertSame([PagesController::class, 'show'], $this->route('GET', '/pages/ref')[0]);
    }

    #[Test]
    public function the_flag_does_not_turn_other_methods_into_page_writes(): void
    {
        self::assertSame([MediaController::class, 'uploadPageMedia'], $this->route('POST', '/pages/blog/media', ['resource' => 'page'])[0]);
        self::assertSame([PagesController::class, 'index'], $this->route('GET', '/pages', ['resource' => 'page'])[0]);
    }

    #[Test]
    public function demo_policy_charges_a_page_delete_not_a_media_delete(): void
    {
        self::assertSame('api.media.delete', DemoPolicy::grantFor('DELETE', '/pages/blog/media/press'));
        self::assertSame('api.pages.delete', DemoPolicy::grantFor('DELETE', '/pages/blog/media/press', true));
        self::assertSame('api.pages.update', DemoPolicy::grantFor('PATCH', '/pages/a/media/b/meta', true));
    }

    /**
     * @param array<string, string> $query
     * @return array{0: array{string, string}, 1: array<string, string>}
     */
    private function route(string $method, string $path, array $query = []): array
    {
        $config = new Config([]);
        $grav = TestHelper::createMockGrav(['config' => $config]);
        $router = new class ($grav, $config) extends ApiRouter {
            /** @var array{0: array{string, string}, 1: array<string, string>}|null */
            public ?array $hit = null;

            public function run(ServerRequestInterface $request, string $path): ResponseInterface
            {
                return $this->dispatch($request, $path);
            }

            protected function createDispatcher(): Dispatcher
            {
                return simpleDispatcher(fn (RouteCollector $r) => $this->registerCoreRoutes($r));
            }

            protected function handleRoute(ServerRequestInterface $request, array $handler, array $vars): ResponseInterface
            {
                $this->hit = [$handler, $vars];

                return ApiResponse::create([]);
            }
        };

        $router->run(TestHelper::createMockRequest(method: $method, path: $path, queryParams: $query), $path);
        self::assertNotNull($router->hit, "{$method} {$path} matched no route");

        return $router->hit;
    }
}
