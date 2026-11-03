<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Pages;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\BlueprintUploadController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A blueprint file field that saves into a page's folder changes that page's
 * media, so it needs the right to update the page, like the page media
 * endpoints (getgrav/grav-plugin-admin2#209).
 */
#[CoversClass(BlueprintUploadController::class)]
class BlueprintUploadPageFolderAclTest extends TestCase
{
    private string $dir;
    private object $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/grav_api_bp_page_acl_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/pages/02.outils', 0777, true);
        mkdir($this->dir . '/pages/04.communication', 0777, true);
        mkdir($this->dir . '/pages/05.blog', 0777, true);
        mkdir($this->dir . '/images', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['pages/02.outils', 'pages/04.communication', 'pages/05.blog', 'pages', 'images', ''] as $sub) {
            @rmdir(rtrim($this->dir . '/' . $sub, '/'));
        }
        Grav::resetInstance();
    }

    /**
     * @param array<string, mixed> $access
     */
    private function guard(array $access, string $dir): void
    {
        $outils = $this->page($this->dir . '/pages/02.outils', ['groups' => ['editors' => 'ru']]);
        $communication = $this->page($this->dir . '/pages/04.communication', ['groups' => ['editors' => 'rl']]);
        $blog = $this->page($this->dir . '/pages/05.blog', null);

        $pages = $this->createMock(Pages::class);
        $pages->method('get')->willReturnCallback(static fn (string $path): ?PageInterface => match ($path) {
            $outils->path() => $outils,
            $communication->path() => $communication,
            $blog->path() => $blog,
            default => null,
        });

        $root = $this->dir;
        $this->user = TestHelper::createMockUser('editor', ['username' => 'editor', 'groups' => ['editors'], 'access' => $access]);
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        $grav = TestHelper::createMockGrav([
            'config' => $config,
            'pages' => $pages,
            'permissions' => new Permissions(),
            'locator' => new class ($root) {
                public function __construct(private readonly string $root) {}
                public function findResource(string $uri, bool $absolute = false): string|false
                {
                    return $uri === 'page://' ? $this->root . '/pages' : false;
                }
            },
        ]);

        $controller = new BlueprintUploadController($grav, $config);
        (new \ReflectionMethod(BlueprintUploadController::class, 'guardPageFolder'))->invoke(
            $controller,
            TestHelper::createMockRequest(attributes: ['api_user' => $this->user]),
            $dir,
        );
    }

    /**
     * @param array<string, mixed>|null $rules
     */
    private function page(string $path, ?array $rules): PageInterface
    {
        $header = ['title' => basename($path)] + ($rules !== null ? ['permissions' => $rules] : []);
        $page = $this->createMock(PageInterface::class);
        $page->method('path')->willReturn($path);
        $page->method('root')->willReturn(false);
        $page->method('header')->willReturn(json_decode((string) json_encode($header)));

        return $page;
    }

    private const MEDIA_ONLY = ['api' => ['access' => true, 'media' => ['write' => true]]];

    #[Test]
    public function a_page_that_grants_update_accepts_the_file_and_its_subfolders_too(): void
    {
        $this->guard(self::MEDIA_ONLY, $this->dir . '/pages/02.outils');
        $this->guard(self::MEDIA_ONLY, $this->dir . '/pages/02.outils/gallery');
        $this->addToAssertionCount(2);
    }

    #[Test]
    public function a_page_the_caller_may_not_update_refuses_it(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->guard(self::MEDIA_ONLY, $this->dir . '/pages/04.communication');
    }

    #[Test]
    public function a_page_without_rules_and_the_pages_root_need_only_media_write(): void
    {
        $this->guard(self::MEDIA_ONLY, $this->dir . '/pages/05.blog');
        $this->guard(self::MEDIA_ONLY, $this->dir . '/pages');
        $this->addToAssertionCount(2);
    }

    #[Test]
    public function folders_outside_the_pages_are_not_its_business(): void
    {
        $this->guard(self::MEDIA_ONLY, $this->dir . '/images');
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function a_super_admin_passes(): void
    {
        $this->guard(['api' => ['access' => true, 'super' => true]], $this->dir . '/pages/04.communication');
        $this->addToAssertionCount(1);
    }
}
