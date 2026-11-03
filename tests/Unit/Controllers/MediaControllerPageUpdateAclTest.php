<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Changing a page's media needs the right to update that page, not just
 * `api.media.write` (getgrav/grav-plugin-admin2#209): the page's own `update`
 * rule when it has one, the account's `api.pages.write` otherwise. Every page
 * media write (upload, delete, metadata save/clear, batch metadata) goes
 * through MediaController::findPageOrFail($request, 'update').
 */
#[CoversClass(MediaController::class)]
class MediaControllerPageUpdateAclTest extends TestCase
{
    private const MEDIA_ONLY = ['api' => ['access' => true, 'pages' => ['read' => true], 'media' => ['read' => true, 'write' => true]]];
    private const EDITOR = ['api' => ['access' => true, 'pages' => ['read' => true, 'write' => true], 'media' => ['read' => true, 'write' => true]]];
    private const SUPER = ['api' => ['access' => true, 'super' => true]];

    private object $user;

    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed>|null $rules the page's `permissions` block
     */
    private function controller(array $access, ?array $rules): MediaController
    {
        $header = ['title' => 'Photos'] + ($rules !== null ? ['permissions' => $rules] : []);
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn(sys_get_temp_dir() . '/grav_api_media_acl/01.photos');
        $page->method('rawRoute')->willReturn('/photos');
        $page->method('route')->willReturn('/photos');
        $page->method('header')->willReturn(json_decode((string) json_encode($header)));

        $pages = $this->createMock(Pages::class);
        $pages->method('find')->willReturnCallback(
            static fn (string $route): ?PageInterface => $route === '/photos' ? $page : null
        );

        $this->user = TestHelper::createMockUser('editor', ['username' => 'editor', 'groups' => ['editors'], 'access' => $access]);
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts(['editor' => $this->user]),
        ]);

        return new MediaController($grav, $config);
    }

    private function request(): ServerRequestInterface
    {
        return TestHelper::createMockRequest(attributes: ['api_user' => $this->user, 'route_params' => ['route' => 'photos']]);
    }

    private function find(MediaController $controller, string $action): PageInterface
    {
        return (new \ReflectionMethod(MediaController::class, 'findPageOrFail'))->invoke($controller, $this->request(), $action);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>|null, bool}>
     */
    public static function cases(): iterable
    {
        yield 'media write alone, page without rules' => [self::MEDIA_ONLY, null, false];
        yield 'media write alone, page grants update to the group' => [self::MEDIA_ONLY, ['groups' => ['editors' => 'ru']], true];
        yield 'media write alone, page grants only read' => [self::MEDIA_ONLY, ['groups' => ['editors' => 'rl']], false];
        yield 'editor, page without rules' => [self::EDITOR, null, true];
        yield 'editor, page denies update' => [self::EDITOR, ['groups' => ['editors' => '-u']], false];
        yield 'editor, page denies update to everyone' => [self::EDITOR, ['groups' => ['defaults' => '-u']], false];
        yield 'super admin, page denies update' => [self::SUPER, ['groups' => ['defaults' => '-u']], true];
    }

    /**
     * @param array<string, mixed> $access
     * @param array<string, mixed>|null $rules
     */
    #[Test]
    #[DataProvider('cases')]
    public function media_writes_need_the_right_to_update_the_page(array $access, ?array $rules, bool $allowed): void
    {
        $controller = $this->controller($access, $rules);

        try {
            $page = $this->find($controller, 'update');
            self::assertTrue($allowed, 'The media write should have been refused.');
            self::assertSame('/photos', $page->rawRoute());
        } catch (ForbiddenException $e) {
            self::assertFalse($allowed, 'The media write should have been allowed: ' . $e->getMessage());
        }
    }

    #[Test]
    public function reading_media_still_only_honours_a_deny(): void
    {
        // Listing media is unchanged: no update right needed, a read deny refuses.
        self::assertSame('/photos', $this->find($this->controller(self::MEDIA_ONLY, null), 'read')->rawRoute());

        $this->expectException(ForbiddenException::class);
        $this->find($this->controller(self::EDITOR, ['groups' => ['editors' => '-r']]), 'read');
    }
}
