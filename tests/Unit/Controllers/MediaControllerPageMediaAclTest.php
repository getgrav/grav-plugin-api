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
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Changing a page's media follows the page's `media` rule — or that block's
 * `update` rule where it says nothing about media — plus `api.media.write`
 * (getgrav/grav-plugin-api#77). A page with no rules keeps the account
 * permission alone. Every page media write (upload, delete, metadata
 * save/clear, batch metadata) goes through
 * MediaController::findPageOrFail($request, 'media').
 */
#[CoversClass(MediaController::class)]
class MediaControllerPageMediaAclTest extends TestCase
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
        yield 'media write alone, page without rules' => [self::MEDIA_ONLY, null, true];
        yield 'media write alone, page grants update to the group' => [self::MEDIA_ONLY, ['groups' => ['editors' => 'ru']], true];
        yield 'media write alone, page grants only read' => [self::MEDIA_ONLY, ['groups' => ['editors' => 'rl']], false];
        yield 'media write alone, page grants media without update' => [self::MEDIA_ONLY, ['groups' => ['editors' => ['read' => true, 'media' => true]]], true];
        yield 'media write alone, page grants media by letter' => [self::MEDIA_ONLY, ['groups' => ['editors' => 'rlm']], true];
        yield 'editor, page withholds media but allows update' => [self::EDITOR, ['groups' => ['editors' => ['update' => true, 'media' => false]]], false];
        yield 'editor, page denies media by letter' => [self::EDITOR, ['groups' => ['editors' => 'ru-m']], false];
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
    public function media_writes_follow_the_pages_media_rule(array $access, ?array $rules, bool $allowed): void
    {
        $controller = $this->controller($access, $rules);

        try {
            $page = $this->find($controller, 'media');
            self::assertTrue($allowed, 'The media write should have been refused.');
            self::assertSame('/photos', $page->rawRoute());
        } catch (ForbiddenException $e) {
            self::assertFalse($allowed, 'The media write should have been allowed: ' . $e->getMessage());
        }
    }

    /**
     * The `permissions.media` flag of a page response is the same verdict the
     * media writes give, so a client can disable the panel up front.
     *
     * @param array<string, mixed> $access
     * @param array<string, mixed>|null $rules
     */
    #[Test]
    #[DataProvider('cases')]
    public function the_media_flag_matches_what_a_media_write_does(array $access, ?array $rules, bool $allowed): void
    {
        $controller = $this->controller($access, $rules);
        $page = $this->find($controller, 'read');

        $capabilities = (new \ReflectionMethod(MediaController::class, 'pageCapabilities'))
            ->invoke($controller, $this->request(), $page);

        self::assertSame($allowed, $capabilities['media']);
    }

    #[Test]
    public function the_media_flag_needs_api_media_write(): void
    {
        $readOnly = ['api' => ['access' => true, 'pages' => ['read' => true, 'write' => true], 'media' => ['read' => true]]];
        $controller = $this->controller($readOnly, null);
        $page = $this->find($controller, 'read');

        $capabilities = (new \ReflectionMethod(MediaController::class, 'pageCapabilities'))
            ->invoke($controller, $this->request(), $page);

        self::assertTrue($capabilities['update']);
        self::assertFalse($capabilities['media']);
    }

    #[Test]
    public function a_media_only_account_can_change_media_where_it_cannot_update(): void
    {
        $controller = $this->controller(self::MEDIA_ONLY, null);
        $page = $this->find($controller, 'read');

        $capabilities = (new \ReflectionMethod(MediaController::class, 'pageCapabilities'))
            ->invoke($controller, $this->request(), $page);

        self::assertFalse($capabilities['update']);
        self::assertTrue($capabilities['media']);
    }

    #[Test]
    public function listing_rows_leave_the_media_flag_out(): void
    {
        $controller = $this->controller(self::EDITOR, null);
        $page = $this->find($controller, 'read');

        $capabilities = (new \ReflectionMethod(MediaController::class, 'pageCapabilities'))
            ->invoke($controller, $this->request(), $page, false);

        self::assertArrayNotHasKey('media', $capabilities);
    }

    #[Test]
    public function a_media_grant_cannot_delete_the_pages_own_files(): void
    {
        $dir = sys_get_temp_dir() . '/grav_api_media_acl/01.photos';
        @mkdir($dir, 0777, true);
        file_put_contents($dir . '/default.md', "---\ntitle: Photos\n---\n");
        file_put_contents($dir . '/frontmatter.yaml', "title: Photos\n");

        try {
            $controller = $this->controller(self::MEDIA_ONLY, ['groups' => ['editors' => ['read' => true, 'media' => true]]]);
            foreach (['default.md', 'DEFAULT.MD', 'frontmatter.yaml'] as $filename) {
                $request = TestHelper::createMockRequest(
                    method: 'DELETE',
                    attributes: ['api_user' => $this->user, 'route_params' => ['route' => 'photos', 'filename' => $filename]],
                );
                try {
                    $controller->deletePageMedia($request);
                    self::fail("Deleting {$filename} through the media route should have been refused.");
                } catch (NotFoundException) {
                }
            }

            self::assertFileExists($dir . '/default.md');
            self::assertFileExists($dir . '/frontmatter.yaml');
        } finally {
            @unlink($dir . '/default.md');
            @unlink($dir . '/frontmatter.yaml');
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
