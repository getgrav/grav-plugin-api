<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Site media listing, search and folder-rename responses: the order sidecar
 * never shows up as a media file, a missing folder still echoes the requested
 * pagination, and a renamed folder reports its real counts.
 */
#[CoversClass(MediaController::class)]
class MediaControllerSiteListingTest extends TestCase
{
    private string $mediaDir;
    private MediaController $controller;
    private object $user;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir() . '/grav_api_media_listing_' . bin2hex(random_bytes(4));
        mkdir($this->mediaDir . '/photos/nested', 0777, true);
        file_put_contents($this->mediaDir . '/photos/a.txt', 'a');
        file_put_contents($this->mediaDir . '/photos/b.txt', 'b');
        file_put_contents($this->mediaDir . '/photos/a.txt.meta.yaml', 'alt: x');
        file_put_contents($this->mediaDir . '/photos/media_order.yaml', "media_order:\n  - b.txt\n");

        $dir = $this->mediaDir;
        $locator = new class ($dir) {
            public function __construct(private readonly string $dir) {}
            public function findResource(string $uri, bool $absolute = false, bool $first = false): string
            {
                return $this->dir;
            }
        };

        $this->user = TestHelper::createMockUser('admin', [
            'access' => ['api' => ['access' => true, 'super' => true]],
        ]);
        $config = new Config(['plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']]]);
        TestHelper::createMockGrav([
            'config' => $config,
            'locator' => $locator,
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts(['admin' => $this->user]),
        ]);
        $this->controller = new MediaController(Grav::instance(), $config);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->mediaDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->mediaDir);
        Grav::resetInstance();
    }

    private function request(array $query = [], string $body = ''): ServerRequestInterface
    {
        $user = $this->user;
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn (string $name, $default = null) => $name === 'api_user' ? $user : $default
        );
        $request->method('getQueryParams')->willReturn($query);
        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    private function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    #[Test]
    public function search_never_returns_the_order_sidecar(): void
    {
        $payload = $this->json($this->controller->siteMedia($this->request(['search' => 'order'])));

        self::assertSame([], $payload['data']);
        self::assertSame(0, $payload['meta']['pagination']['total']);
    }

    /**
     * Hidden folders the listing never shows, at the top level and nested,
     * plus one visible nested hit so the walk is proven to still recurse.
     */
    private function seedHiddenFolders(): void
    {
        mkdir($this->mediaDir . '/.private/deeper', 0777, true);
        mkdir($this->mediaDir . '/photos/.cache', 0777, true);
        file_put_contents($this->mediaDir . '/.private/secret-top.txt', 'x');
        file_put_contents($this->mediaDir . '/.private/deeper/secret-deep.txt', 'x');
        file_put_contents($this->mediaDir . '/photos/.cache/secret-thumb.txt', 'x');
        file_put_contents($this->mediaDir . '/photos/nested/secret-visible.txt', 'x');
        file_put_contents($this->mediaDir . '/photos/nested/.secret-dotfile.txt', 'x');
    }

    #[Test]
    public function search_never_descends_into_hidden_folders(): void
    {
        $this->seedHiddenFolders();

        $payload = $this->json($this->controller->siteMedia($this->request(['search' => 'secret'])));

        // Only the visible nested file: nothing from .private/, .private/deeper/
        // or photos/.cache/, which the listing and every path route refuse (#50).
        self::assertSame(1, $payload['meta']['pagination']['total']);
        self::assertSame('secret-visible.txt', $payload['data'][0]['filename']);
        self::assertSame('photos/nested', $payload['data'][0]['path']);
    }

    #[Test]
    public function media_file_walk_counts_only_what_the_listing_shows(): void
    {
        $this->seedHiddenFolders();

        $names = [];
        foreach (MediaController::walkMediaFiles($this->mediaDir) as $file) {
            $names[] = $file->getFilename();
        }
        sort($names);

        // No sidecars, no dotfiles, nothing under a dot-folder.
        self::assertSame(['a.txt', 'b.txt', 'secret-visible.txt'], $names);
    }

    #[Test]
    public function missing_folder_echoes_the_requested_pagination(): void
    {
        $payload = $this->json($this->controller->siteMedia(
            $this->request(['path' => 'does-not-exist', 'page' => '3', 'per_page' => '5'])
        ));

        self::assertSame([], $payload['data']);
        self::assertSame(3, $payload['meta']['pagination']['page']);
        self::assertSame(5, $payload['meta']['pagination']['per_page']);
    }

    #[Test]
    public function renamed_folder_reports_its_real_counts(): void
    {
        $payload = $this->json($this->controller->renameFolder(
            $this->request([], json_encode(['from' => 'photos', 'to' => 'pictures']))
        ));

        self::assertSame('pictures', $payload['data']['path']);
        self::assertSame(1, $payload['data']['children_count']);
        // a.txt + b.txt; the .meta.yaml and media_order.yaml sidecars don't count.
        self::assertSame(2, $payload['data']['file_count']);
    }
}
