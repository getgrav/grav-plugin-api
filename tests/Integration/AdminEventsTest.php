<?php

declare(strict_types=1);

// Load the API plugin's autoloader so its controller classes are available
require_once '/Users/rhuk/Projects/grav/grav-plugin-api/vendor/autoload.php';

use Codeception\Util\Fixtures;
use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Config\Config;
use Grav\Common\Data\Data;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Controllers\UsersController;
use Grav\Plugin\Api\Controllers\ConfigController;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Integration tests verifying that API controllers fire admin-compatible events.
 *
 * These tests use the real Grav framework to ensure events fire through the
 * actual event dispatcher and that third-party plugins subscribing to
 * onAdmin* events would be triggered correctly.
 */
class AdminEventsTest extends \PHPUnit\Framework\TestCase
{
    protected Grav $grav;
    protected Pages $pages;
    protected string $tempDir;

    /** @var array<int, array{name: string, event: Event}> */
    protected array $capturedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $grav = Fixtures::get('grav');
        $this->grav = $grav();

        $this->tempDir = sys_get_temp_dir() . '/grav_api_events_test_' . uniqid();
        @mkdir($this->tempDir . '/pages', 0775, true);
        @mkdir($this->tempDir . '/cache/api/thumbnails', 0775, true);
        @mkdir($this->tempDir . '/user/config', 0775, true);

        $this->pages = $this->grav['pages'];

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $locator->addPath('page', '', $this->tempDir . '/pages', false);
        $locator->addPath('cache', '', $this->tempDir . '/cache', false);

        // Set up API plugin config
        $this->grav['config']->set('plugins.api.route', '/api');
        $this->grav['config']->set('plugins.api.version_prefix', 'v1');
        $this->grav['config']->set('plugins.api.pagination.default_per_page', 20);
        $this->grav['config']->set('plugins.api.pagination.max_per_page', 100);
        $this->grav['config']->set('plugins.api.batch.max_items', 50);

        $this->capturedEvents = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // PagesController: create
    // -------------------------------------------------------------------------

    public function testCreatePageFiresOnAdminCreatePageFrontmatter(): void
    {
        $captured = [];
        $this->grav['events']->addListener('onAdminCreatePageFrontmatter', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/test-create',
            'title' => 'Test Create',
            'content' => 'Hello world',
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $captured, 'onAdminCreatePageFrontmatter should fire once');
        self::assertArrayHasKey('header', $captured[0]->toArray());
        self::assertArrayHasKey('data', $captured[0]->toArray());
    }

    public function testCreatePageFiresOnAdminSaveBeforeSave(): void
    {
        $saveOrder = [];
        $this->grav['events']->addListener('onAdminSave', function (Event $event) use (&$saveOrder) {
            $saveOrder[] = 'onAdminSave';
            // Verify object is a Page
            self::assertInstanceOf(Page::class, $event['object']);
            self::assertArrayHasKey('page', $event->toArray());
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/test-save-order',
            'title' => 'Save Order Test',
        ]);

        $controller->create($request);

        self::assertContains('onAdminSave', $saveOrder);
    }

    public function testCreatePageFiresOnAdminAfterSave(): void
    {
        $captured = [];
        $this->grav['events']->addListener('onAdminAfterSave', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/test-after-save',
            'title' => 'After Save Test',
        ]);

        $controller->create($request);

        self::assertCount(1, $captured, 'onAdminAfterSave should fire once');
        self::assertArrayHasKey('object', $captured[0]->toArray());
        self::assertArrayHasKey('page', $captured[0]->toArray());
    }

    public function testCreatePageFrontmatterEventCanModifyHeader(): void
    {
        // The real Grav Event uses ArrayAccess. To modify a referenced array
        // inside an event, the plugin pattern is to read, modify, write back.
        $this->grav['events']->addListener('onAdminCreatePageFrontmatter', function (Event $event) {
            $header = $event['header'];
            $header['injected_by_plugin'] = true;
            $event['header'] = $header;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/test-inject',
            'title' => 'Inject Test',
        ]);

        $controller->create($request);

        // Verify the injected field made it into the saved page
        $this->pages->reset();
        $this->pages->init();
        $page = $this->pages->find('/test-inject');

        self::assertNotNull($page, 'Page should exist after creation');
        self::assertTrue(
            property_exists($page->header(), 'injected_by_plugin') && $page->header()->injected_by_plugin === true,
            'onAdminCreatePageFrontmatter should be able to inject fields into the header'
        );
    }

    // -------------------------------------------------------------------------
    // PagesController: update
    // -------------------------------------------------------------------------

    public function testUpdatePageFiresOnAdminSaveAndAfterSave(): void
    {
        // Create a page first
        $this->createTestPage('/update-test', 'Update Test');

        $saveEvents = [];
        $afterSaveEvents = [];

        $this->grav['events']->addListener('onAdminSave', function (Event $event) use (&$saveEvents) {
            $saveEvents[] = $event;
        });
        $this->grav['events']->addListener('onAdminAfterSave', function (Event $event) use (&$afterSaveEvents) {
            $afterSaveEvents[] = $event;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('PATCH', '/api/v1/pages/update-test', [
            'title' => 'Updated Title',
        ], ['route' => 'update-test']);

        $response = $controller->update($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $saveEvents, 'onAdminSave should fire once on update');
        self::assertCount(1, $afterSaveEvents, 'onAdminAfterSave should fire once on update');

        // Both events should have 'object' and 'page' keys
        self::assertArrayHasKey('page', $saveEvents[0]->toArray());
        self::assertArrayHasKey('page', $afterSaveEvents[0]->toArray());
    }

    public function testUpdatePageOnAdminSaveCanModifyPage(): void
    {
        $this->createTestPage('/modify-test', 'Modify Test');

        $this->grav['events']->addListener('onAdminSave', function (Event $event) {
            $page = $event['object'];
            $header = (array) $page->header();
            $header['modified_by_plugin'] = 'seo-magic';
            $page->header((object) $header);
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('PATCH', '/api/v1/pages/modify-test', [
            'title' => 'Modified',
        ], ['route' => 'modify-test']);

        $controller->update($request);

        // Re-read the page to verify
        $this->pages->reset();
        $this->pages->init();
        $page = $this->pages->find('/modify-test');

        self::assertNotNull($page);
        self::assertSame('seo-magic', $page->header()->modified_by_plugin ?? null);
    }

    // -------------------------------------------------------------------------
    // PagesController: delete
    // -------------------------------------------------------------------------

    public function testDeletePageFiresOnAdminAfterDelete(): void
    {
        $this->createTestPage('/delete-test', 'Delete Test');

        $captured = [];
        $this->grav['events']->addListener('onAdminAfterDelete', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('DELETE', '/api/v1/pages/delete-test', [], ['route' => 'delete-test']);

        $response = $controller->delete($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $captured, 'onAdminAfterDelete should fire once');
        self::assertArrayHasKey('object', $captured[0]->toArray());
        self::assertArrayHasKey('page', $captured[0]->toArray());
    }

    // -------------------------------------------------------------------------
    // PagesController: move
    // -------------------------------------------------------------------------

    public function testMovePageFiresOnAdminAfterSaveAs(): void
    {
        $this->createTestPage('/move-source', 'Move Source');
        $this->createTestPage('/move-target', 'Move Target');

        $captured = [];
        $this->grav['events']->addListener('onAdminAfterSaveAs', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages/move-source/move', [
            'parent' => '/move-target',
        ], ['route' => 'move-source']);

        $controller->move($request);

        self::assertCount(1, $captured, 'onAdminAfterSaveAs should fire once');
        self::assertArrayHasKey('path', $captured[0]->toArray());
        self::assertStringContainsString('move-source', $captured[0]['path']);
    }

    // -------------------------------------------------------------------------
    // MediaController: upload & delete
    // -------------------------------------------------------------------------

    public function testMediaUploadFiresOnAdminAfterAddMedia(): void
    {
        $this->createTestPage('/media-test', 'Media Test');

        $captured = [];
        $this->grav['events']->addListener('onAdminAfterAddMedia', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createMediaController();

        // Create a temp file to upload
        $tmpFile = $this->tempDir . '/upload.txt';
        file_put_contents($tmpFile, 'test content');

        $uploadedFile = $this->createUploadedFile($tmpFile, 'test-upload.txt', 'text/plain');

        $request = $this->makeRequest('POST', '/api/v1/pages/media-test/media', [], ['route' => 'media-test']);
        $request = $request->withUploadedFiles(['file' => $uploadedFile]);

        $response = $controller->uploadPageMedia($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $captured, 'onAdminAfterAddMedia should fire once');
        self::assertArrayHasKey('object', $captured[0]->toArray());
        self::assertArrayHasKey('page', $captured[0]->toArray());
    }

    public function testMediaDeleteFiresOnAdminAfterDelMedia(): void
    {
        $this->createTestPage('/media-del-test', 'Media Del Test');

        // Put a file in the page directory
        $pagePath = $this->tempDir . '/pages/media-del-test';
        file_put_contents($pagePath . '/photo.txt', 'test');

        $captured = [];
        $this->grav['events']->addListener('onAdminAfterDelMedia', function (Event $event) use (&$captured) {
            $captured[] = $event;
        });

        $controller = $this->createMediaController();
        $request = $this->makeRequest('DELETE', '/api/v1/pages/media-del-test/media/photo.txt', [], [
            'route' => 'media-del-test',
            'filename' => 'photo.txt',
        ]);

        $response = $controller->deletePageMedia($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertCount(1, $captured, 'onAdminAfterDelMedia should fire once');
        self::assertArrayHasKey('object', $captured[0]->toArray());
        self::assertArrayHasKey('page', $captured[0]->toArray());
        self::assertArrayHasKey('filename', $captured[0]->toArray());
        self::assertSame('photo.txt', $captured[0]['filename']);
    }

    // -------------------------------------------------------------------------
    // UsersController: create & update
    // -------------------------------------------------------------------------

    public function testUserCreateFiresOnAdminSaveAndAfterSave(): void
    {
        $saveEvents = [];
        $afterSaveEvents = [];

        $this->grav['events']->addListener('onAdminSave', function (Event $event) use (&$saveEvents) {
            $saveEvents[] = $event;
        });
        $this->grav['events']->addListener('onAdminAfterSave', function (Event $event) use (&$afterSaveEvents) {
            $afterSaveEvents[] = $event;
        });

        $controller = $this->createUsersController();
        $request = $this->makeRequest('POST', '/api/v1/users', [
            'username' => 'testuser_' . uniqid(),
            'password' => 'TestPass123!',
            'email' => 'test@example.com',
        ]);

        $response = $controller->create($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $saveEvents, 'onAdminSave should fire once for user create');
        self::assertCount(1, $afterSaveEvents, 'onAdminAfterSave should fire once for user create');
        self::assertArrayHasKey('object', $saveEvents[0]->toArray());
    }

    public function testUserUpdateFiresOnAdminSaveAndAfterSave(): void
    {
        // Create user first
        $username = 'updateuser_' . uniqid();
        $accounts = $this->grav['accounts'];
        $user = $accounts->load($username);
        $user->set('email', 'before@example.com');
        $user->set('fullname', 'Before');
        $user->set('state', 'enabled');
        $user->set('hashed_password', password_hash('test', PASSWORD_DEFAULT));
        $user->save();

        $saveEvents = [];
        $afterSaveEvents = [];

        $this->grav['events']->addListener('onAdminSave', function (Event $event) use (&$saveEvents) {
            $saveEvents[] = $event;
        });
        $this->grav['events']->addListener('onAdminAfterSave', function (Event $event) use (&$afterSaveEvents) {
            $afterSaveEvents[] = $event;
        });

        $controller = $this->createUsersController();
        $request = $this->makeRequest('PATCH', '/api/v1/users/' . $username, [
            'fullname' => 'After',
        ], ['username' => $username]);

        $response = $controller->update($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $saveEvents, 'onAdminSave should fire once for user update');
        self::assertCount(1, $afterSaveEvents, 'onAdminAfterSave should fire once for user update');
    }

    // -------------------------------------------------------------------------
    // ConfigController: update
    // -------------------------------------------------------------------------

    public function testConfigUpdateFiresOnAdminSaveAndAfterSave(): void
    {
        $saveEvents = [];
        $afterSaveEvents = [];

        $this->grav['events']->addListener('onAdminSave', function (Event $event) use (&$saveEvents) {
            $saveEvents[] = $event;
        });
        $this->grav['events']->addListener('onAdminAfterSave', function (Event $event) use (&$afterSaveEvents) {
            $afterSaveEvents[] = $event;
        });

        $controller = $this->createConfigController();
        $request = $this->makeRequest('PATCH', '/api/v1/config/site', [
            'title' => 'Updated Site Title',
        ], ['scope' => 'site']);

        $response = $controller->update($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $saveEvents, 'onAdminSave should fire once for config update');
        self::assertCount(1, $afterSaveEvents, 'onAdminAfterSave should fire once for config update');

        // Config saves wrap in Data object
        self::assertInstanceOf(Data::class, $saveEvents[0]['object']);
    }

    public function testConfigOnAdminSaveCanModifyData(): void
    {
        // Plugins modify the Data object in-place via set().
        // Since objects are passed by identity, changes are visible to the caller.
        $this->grav['events']->addListener('onAdminSave', function (Event $event) {
            $obj = $event['object'];
            if ($obj instanceof Data) {
                $obj->set('injected', 'by-plugin');
            }
        });

        $controller = $this->createConfigController();
        $request = $this->makeRequest('PATCH', '/api/v1/config/site', [
            'title' => 'Config Modify Test',
        ], ['scope' => 'site']);

        $response = $controller->update($request);
        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());

        // The Data object was modified in-place by the listener, and the
        // controller calls $obj->toArray() after the event, so the injected
        // value should appear in the response (inside the 'data' wrapper).
        $data = $body['data'] ?? $body;
        self::assertSame('by-plugin', $data['injected'] ?? null, 'Plugin should be able to inject config values via onAdminSave');
    }

    // -------------------------------------------------------------------------
    // Event ordering: admin events fire before API events
    // -------------------------------------------------------------------------

    public function testAdminEventsFireBeforeApiEvents(): void
    {
        $order = [];

        $this->grav['events']->addListener('onAdminSave', function () use (&$order) {
            $order[] = 'onAdminSave';
        });
        $this->grav['events']->addListener('onAdminAfterSave', function () use (&$order) {
            $order[] = 'onAdminAfterSave';
        });
        $this->grav['events']->addListener('onApiPageCreated', function () use (&$order) {
            $order[] = 'onApiPageCreated';
        });
        $this->grav['events']->addListener('onApiBeforePageCreate', function () use (&$order) {
            $order[] = 'onApiBeforePageCreate';
        });
        $this->grav['events']->addListener('onAdminCreatePageFrontmatter', function () use (&$order) {
            $order[] = 'onAdminCreatePageFrontmatter';
        });

        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/order-test',
            'title' => 'Order Test',
        ]);

        $controller->create($request);

        // Expected order:
        // 1. onApiBeforePageCreate (API before event)
        // 2. onAdminCreatePageFrontmatter (admin frontmatter injection)
        // 3. onAdminSave (admin pre-save)
        // 4. onAdminAfterSave (admin post-save)
        // 5. onApiPageCreated (API after event)
        self::assertSame([
            'onApiBeforePageCreate',
            'onAdminCreatePageFrontmatter',
            'onAdminSave',
            'onAdminAfterSave',
            'onApiPageCreated',
        ], $order, 'Events should fire in the correct order');
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createPagesController(): PagesController
    {
        return new PagesController($this->grav, $this->grav['config']);
    }

    private function createMediaController(): MediaController
    {
        return new MediaController($this->grav, $this->grav['config']);
    }

    private function createUsersController(): UsersController
    {
        return new UsersController($this->grav, $this->grav['config']);
    }

    private function createConfigController(): ConfigController
    {
        return new ConfigController($this->grav, $this->grav['config']);
    }

    private function createTestPage(string $route, string $title, string $content = ''): void
    {
        $slug = ltrim($route, '/');
        $dir = $this->tempDir . '/pages/' . $slug;
        @mkdir($dir, 0775, true);
        file_put_contents($dir . '/default.md', "---\ntitle: {$title}\n---\n{$content}");
        $this->pages->reset();
        $this->pages->init();
    }

    private function makeRequest(
        string $method,
        string $path,
        array $body = [],
        array $routeParams = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $superAdmin = $this->createSuperAdmin();

        return new class ($method, $path, $body, $routeParams, $superAdmin) implements \Psr\Http\Message\ServerRequestInterface {
            private array $attributes;
            private array $uploadedFiles = [];

            public function __construct(
                private readonly string $method,
                private readonly string $path,
                private readonly array $body,
                array $routeParams,
                object $user,
            ) {
                $this->attributes = [
                    'api_user' => $user,
                    'json_body' => $body,
                    'route_params' => $routeParams,
                ];
            }

            public function getMethod(): string { return $this->method; }
            public function getQueryParams(): array { return []; }
            public function getServerParams(): array { return []; }
            public function getAttribute(string $name, mixed $default = null): mixed { return $this->attributes[$name] ?? $default; }
            public function getHeaderLine(string $name): string { return ''; }
            public function getHeader(string $name): array { return []; }
            public function hasHeader(string $name): bool { return false; }
            public function getHeaders(): array { return []; }
            public function getParsedBody(): mixed { return $this->body; }
            public function getUploadedFiles(): array { return $this->uploadedFiles; }

            public function withUploadedFiles(array $uploadedFiles): static {
                $clone = clone $this;
                $clone->uploadedFiles = $uploadedFiles;
                return $clone;
            }

            public function withAttribute(string $name, mixed $value): static {
                $clone = clone $this;
                $clone->attributes[$name] = $value;
                return $clone;
            }

            // Stubs for remaining PSR-7 methods
            public function getUri(): \Psr\Http\Message\UriInterface {
                $path = $this->path;
                return new class($path) implements \Psr\Http\Message\UriInterface {
                    public function __construct(private string $p) {}
                    public function getScheme(): string { return 'https'; }
                    public function getAuthority(): string { return ''; }
                    public function getUserInfo(): string { return ''; }
                    public function getHost(): string { return 'localhost'; }
                    public function getPort(): ?int { return null; }
                    public function getPath(): string { return $this->p; }
                    public function getQuery(): string { return ''; }
                    public function getFragment(): string { return ''; }
                    public function withScheme(string $scheme): static { return clone $this; }
                    public function withUserInfo(string $user, ?string $password = null): static { return clone $this; }
                    public function withHost(string $host): static { return clone $this; }
                    public function withPort(?int $port): static { return clone $this; }
                    public function withPath(string $path): static { return clone $this; }
                    public function withQuery(string $query): static { return clone $this; }
                    public function withFragment(string $fragment): static { return clone $this; }
                    public function __toString(): string { return $this->p; }
                };
            }
            public function getBody(): \Psr\Http\Message\StreamInterface {
                $c = json_encode($this->body);
                return new class($c) implements \Psr\Http\Message\StreamInterface {
                    public function __construct(private string $c) {}
                    public function __toString(): string { return $this->c; }
                    public function close(): void {}
                    public function detach() { return null; }
                    public function getSize(): ?int { return strlen($this->c); }
                    public function tell(): int { return 0; }
                    public function eof(): bool { return true; }
                    public function isSeekable(): bool { return false; }
                    public function seek(int $offset, int $whence = SEEK_SET): void {}
                    public function rewind(): void {}
                    public function isWritable(): bool { return false; }
                    public function write(string $string): int { return 0; }
                    public function isReadable(): bool { return true; }
                    public function read(int $length): string { return $this->c; }
                    public function getContents(): string { return $this->c; }
                    public function getMetadata(?string $key = null): mixed { return null; }
                };
            }
            public function getRequestTarget(): string { return $this->path; }
            public function withRequestTarget(string $requestTarget): static { return clone $this; }
            public function withMethod(string $method): static { return clone $this; }
            public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): static { return clone $this; }
            public function getProtocolVersion(): string { return '1.1'; }
            public function withProtocolVersion(string $version): static { return clone $this; }
            public function withHeader(string $name, $value): static { return clone $this; }
            public function withAddedHeader(string $name, $value): static { return clone $this; }
            public function withoutHeader(string $name): static { return clone $this; }
            public function withBody(\Psr\Http\Message\StreamInterface $body): static { return clone $this; }
            public function getCookieParams(): array { return []; }
            public function withCookieParams(array $cookies): static { return clone $this; }
            public function withQueryParams(array $query): static { return clone $this; }
            public function withParsedBody($data): static { return clone $this; }
            public function getAttributes(): array { return $this->attributes; }
            public function withoutAttribute(string $name): static {
                $clone = clone $this;
                unset($clone->attributes[$name]);
                return $clone;
            }
        };
    }

    private function createSuperAdmin(): UserInterface
    {
        /** @var \Grav\Common\User\Interfaces\UserCollectionInterface $accounts */
        $accounts = $this->grav['accounts'];
        $user = $accounts->load('admin');

        // If user doesn't exist, create it
        if (!$user->exists()) {
            $user->set('email', 'admin@test.com');
            $user->set('fullname', 'Test Admin');
            $user->set('state', 'enabled');
            $user->set('access', [
                'admin' => ['super' => true, 'login' => true],
                'api' => ['access' => true, 'pages' => ['read' => true, 'write' => true], 'media' => ['read' => true, 'write' => true], 'users' => ['read' => true, 'write' => true], 'config' => ['read' => true, 'write' => true]],
            ]);
            $user->save();
        }

        return $user;
    }

    private function createUploadedFile(string $tmpPath, string $clientName, string $mediaType): \Psr\Http\Message\UploadedFileInterface
    {
        $size = filesize($tmpPath);
        return new class($tmpPath, $clientName, $mediaType, $size) implements \Psr\Http\Message\UploadedFileInterface {
            private bool $moved = false;
            public function __construct(
                private readonly string $tmpPath,
                private readonly string $clientName,
                private readonly string $mediaType,
                private readonly int $fileSize,
            ) {}
            public function getStream(): \Psr\Http\Message\StreamInterface { throw new \RuntimeException('Not implemented'); }
            public function moveTo(string $targetPath): void { copy($this->tmpPath, $targetPath); $this->moved = true; }
            public function getSize(): int { return $this->fileSize; }
            public function getError(): int { return UPLOAD_ERR_OK; }
            public function getClientFilename(): string { return $this->clientName; }
            public function getClientMediaType(): string { return $this->mediaType; }
        };
    }

    private static function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        self::assertStringContainsString($needle, $haystack, $message);
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmrf($path) : unlink($path);
        }
        rmdir($dir);
    }
}
