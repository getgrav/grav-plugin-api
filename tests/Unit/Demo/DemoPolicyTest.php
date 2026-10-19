<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Demo;

use Grav\Plugin\Api\Demo\DemoPolicy;
use Grav\Plugin\Api\Exceptions\DemoModeException;
use Grav\Plugin\Api\Middleware\DemoModeMiddleware;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a demo account may change. A demo site's owner chooses by grant, and
 * the route a write goes to decides which grant it needs, so "edit pages but
 * never delete one" or "change plugin settings but not site settings" are each
 * one list. Some routes and settings stay refused whatever is granted.
 */
#[CoversClass(DemoPolicy::class)]
#[CoversClass(DemoModeMiddleware::class)]
class DemoPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function routes(): iterable
    {
        yield 'create a page' => ['POST', '/pages', 'api.pages.create'];
        yield 'edit a page' => ['PATCH', '/pages/blog/first', 'api.pages.update'];
        yield 'delete a page' => ['DELETE', '/pages/blog/first', 'api.pages.delete'];
        yield 'copy a page' => ['POST', '/pages/blog/first/copy', 'api.pages.create'];
        yield 'translate a page' => ['POST', '/pages/blog/first/translate', 'api.pages.create'];
        yield 'move a page' => ['POST', '/pages/blog/first/move', 'api.pages.update'];
        yield 'reorder children' => ['POST', '/pages/blog/reorder', 'api.pages.update'];
        yield 'reorganize the tree' => ['POST', '/pages/reorganize', 'api.pages.update'];
        yield 'batch decides from the body' => ['POST', '/pages/batch', DemoPolicy::BATCH];
        yield 'preview link for a draft' => ['POST', '/pages/blog/first/preview-token', DemoPolicy::OPEN];

        yield 'upload to a page' => ['POST', '/pages/blog/first/media', 'api.media.upload'];
        yield 'delete page media' => ['DELETE', '/pages/blog/first/media/a.jpg', 'api.media.delete'];
        yield 'edit page media details' => ['PATCH', '/pages/blog/first/media/a.jpg/meta', 'api.media.update'];
        yield 'clear page media details' => ['DELETE', '/pages/blog/first/media/a.jpg/meta', 'api.media.update'];
        yield 'a file called meta is a delete, as the router reads it' => ['DELETE', '/pages/foo/media/media/meta', 'api.media.delete'];
        yield 'upload site media' => ['POST', '/media', 'api.media.upload'];
        yield 'new media folder' => ['POST', '/media/folders', 'api.media.upload'];
        yield 'rename site media' => ['POST', '/media/rename', 'api.media.update'];
        yield 'delete site media' => ['DELETE', '/media/photos/a.jpg', 'api.media.delete'];
        yield 'delete a media folder' => ['DELETE', '/media/folders/photos', 'api.media.delete'];
        yield 'blueprint upload' => ['POST', '/blueprint-upload', 'api.media.upload'];

        yield 'site settings' => ['PATCH', '/config/site', 'api.config.site'];
        yield 'a plugin\'s settings' => ['PATCH', '/config/plugins/simplesearch', 'api.config.plugins'];
        yield 'a theme\'s settings' => ['PATCH', '/config/themes/quark', 'api.config.themes'];
        yield 'revert a plugin setting' => ['POST', '/config/plugins/simplesearch/revert', 'api.config.plugins'];

        yield 'system settings stay locked' => ['PATCH', '/config/system', DemoPolicy::DENY];
        yield 'security settings stay locked' => ['PATCH', '/config/security', DemoPolicy::DENY];
        yield 'media types stay locked' => ['PATCH', '/config/media', DemoPolicy::DENY];
        yield 'scheduler settings stay locked' => ['PATCH', '/config/scheduler', DemoPolicy::DENY];
        yield 'the API\'s own settings stay locked' => ['PATCH', '/config/plugins/api', DemoPolicy::DENY];
        yield 'the admin\'s settings stay locked' => ['PATCH', '/config/plugins/admin2', DemoPolicy::DENY];
        yield 'sign-in settings stay locked' => ['PATCH', '/config/plugins/login', DemoPolicy::DENY];
        yield 'mail settings stay locked' => ['PATCH', '/config/plugins/email', DemoPolicy::DENY];
        yield 'account settings stay locked' => ['PATCH', '/config/accounts', DemoPolicy::DENY];

        yield 'users' => ['PATCH', '/users/demo', DemoPolicy::DENY];
        yield 'packages' => ['POST', '/gpm/install', DemoPolicy::DENY];
        yield 'clearing the cache' => ['DELETE', '/cache', DemoPolicy::DENY];
        yield 'backups' => ['POST', '/system/backup', DemoPolicy::DENY];
        yield 'demo controls' => ['POST', '/demo/baseline', DemoPolicy::DENY];
        yield 'a plugin route' => ['POST', '/courses/1/progress', DemoPolicy::DENY];
    }

    #[Test]
    #[DataProvider('routes')]
    public function each_write_needs_its_own_grant(string $method, string $path, string $grant): void
    {
        self::assertSame($grant, DemoPolicy::grantFor($method, $path));
    }

    #[Test]
    public function shorthand_grants_stand_for_their_finer_ones(): void
    {
        self::assertSame(
            ['api.pages.write', 'api.pages.create', 'api.pages.update', 'api.pages.delete'],
            DemoPolicy::expand(['api.pages.write'])
        );
        self::assertSame([], DemoPolicy::expand([]));
    }

    #[Test]
    public function admin2_sees_the_shorthand_whenever_part_of_it_is_granted(): void
    {
        $coarse = DemoPolicy::coarse(['api.pages.update', 'api.config.plugins']);

        self::assertContains('api.pages.write', $coarse);
        self::assertContains('api.config.write', $coarse);
        self::assertNotContains('api.media.write', $coarse);
        self::assertSame([], DemoPolicy::coarse([]));
    }

    #[Test]
    public function edit_without_delete(): void
    {
        $grants = DemoPolicy::expand(['api.pages.create', 'api.pages.update']);

        self::assertTrue(DemoPolicy::allows('PATCH', '/pages/blog/first', $grants));
        self::assertTrue(DemoPolicy::allows('POST', '/pages', $grants));
        self::assertFalse(DemoPolicy::allows('DELETE', '/pages/blog/first', $grants));
        self::assertFalse(DemoPolicy::allows('POST', '/pages/blog/first/media', $grants));
    }

    #[Test]
    public function batch_operations_need_the_grant_their_operation_does(): void
    {
        self::assertSame('api.pages.update', DemoPolicy::batchGrant('publish'));
        self::assertSame('api.pages.update', DemoPolicy::batchGrant('unpublish'));
        self::assertSame('api.pages.delete', DemoPolicy::batchGrant('delete'));
        self::assertSame('api.pages.create', DemoPolicy::batchGrant('copy'));
        self::assertSame(DemoPolicy::DENY, DemoPolicy::batchGrant('anything-else'));
    }

    #[Test]
    public function no_grant_opens_a_locked_setting(): void
    {
        $everything = DemoPolicy::expand(['api.pages.write', 'api.media.write', 'api.config.write', 'api.config.system']);

        self::assertTrue(DemoPolicy::allows('PATCH', '/config/plugins/simplesearch', $everything));
        self::assertFalse(DemoPolicy::allows('PATCH', '/config/system', $everything));
        self::assertFalse(DemoPolicy::allows('PATCH', '/config/plugins/api', $everything));
    }

    #[Test]
    public function allow_routes_open_a_plugin_route_and_nothing_locked(): void
    {
        $routes = ['POST /courses/*/progress', '/quiz/*'];

        self::assertTrue(DemoPolicy::allows('POST', '/courses/12/progress', [], $routes));
        self::assertFalse(DemoPolicy::allows('PATCH', '/courses/12/progress', [], $routes));
        self::assertTrue(DemoPolicy::allows('DELETE', '/quiz/3/answers', [], $routes));
        self::assertFalse(DemoPolicy::allows('POST', '/courses', [], $routes));

        // Not even a catch-all opens the routes that are locked.
        self::assertFalse(DemoPolicy::allows('POST', '/gpm/install', [], ['*']));
        self::assertFalse(DemoPolicy::allows('DELETE', '/cache', [], ['*']));
        self::assertFalse(DemoPolicy::allows('PATCH', '/config/site', [], ['*']));
        self::assertFalse(DemoPolicy::allows('DELETE', '/pages/home', [], ['*']));
    }

    #[Test]
    public function an_allowed_route_never_passes_the_apis_own_permission_checks(): void
    {
        self::assertTrue(DemoPolicy::isCorePermission('api.system.write'));
        self::assertTrue(DemoPolicy::isCorePermission('api.config.write'));
        self::assertTrue(DemoPolicy::isCorePermission('admin.super'));
        self::assertFalse(DemoPolicy::isCorePermission('api.lms.write'));
        self::assertFalse(DemoPolicy::isCorePermission('api.access'));
        self::assertFalse(DemoPolicy::isCorePermission('api.pagesx.write'));
    }

    #[Test]
    public function the_middleware_marks_what_it_let_through(): void
    {
        $demo = TestHelper::createMockUser('demo', ['access.api.demo' => true]);
        $owner = TestHelper::createMockUser('owner', []);
        $middleware = new DemoModeMiddleware(['api.pages.update'], ['POST /courses/*']);

        self::assertSame('api.pages.update', $middleware->check($demo, 'PATCH', '/pages/home', false));
        self::assertSame('allow_routes', $middleware->check($demo, 'POST', '/courses/1', false));
        self::assertNull($middleware->check($demo, 'GET', '/config/system', false));
        self::assertNull($middleware->check($demo, 'POST', '/auth/token', true));
        self::assertNull($middleware->check($owner, 'DELETE', '/pages/home', false));

        $this->expectException(DemoModeException::class);
        $middleware->check($demo, 'DELETE', '/pages/home', false);
    }

    #[Test]
    public function a_read_only_demo_refuses_every_write(): void
    {
        $demo = TestHelper::createMockUser('demo', ['access.api.demo' => true]);
        $middleware = new DemoModeMiddleware([], []);

        foreach ([['POST', '/pages'], ['PATCH', '/pages/home'], ['POST', '/media'], ['PATCH', '/config/site']] as [$method, $path]) {
            try {
                $middleware->check($demo, $method, $path, false);
                self::fail("{$method} {$path} was let through");
            } catch (DemoModeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
