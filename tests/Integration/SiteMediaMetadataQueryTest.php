<?php

declare(strict_types=1);

// Load the API plugin's autoloader so its controller classes are available
require_once '/Users/rhuk/Projects/grav/grav-plugin-api/vendor/autoload.php';

use Codeception\Util\Fixtures;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\MediaController;
use Grav\Plugin\Api\Exceptions\ValidationException;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Coverage for getgrav/grav#4210: the site media listing (`GET /media`) gains
 * the same `?filter=`/`?sort=` metadata querying page media received in #4200.
 *
 * A metadata query builds a real `Grav\Common\Page\Media` collection for the
 * current folder and reuses `applyMediaQuery()`. Sorting overrides the folder's
 * manual-order sidecar; filtering alone preserves that manual order. Combining a
 * recursive `?search` with a metadata query is rejected with a 400.
 */
class SiteMediaMetadataQueryTest extends \PHPUnit\Framework\TestCase
{
    protected Grav $grav;
    protected string $tempDir;
    protected string $mediaDir;

    protected function setUp(): void
    {
        parent::setUp();

        $grav = Fixtures::get('grav');
        $this->grav = $grav();

        $this->tempDir = sys_get_temp_dir() . '/grav_api_site_media_test_' . uniqid();
        $this->mediaDir = $this->tempDir . '/media';
        @mkdir($this->mediaDir, 0775, true);
        @mkdir($this->tempDir . '/cache', 0775, true);

        // Prepend the temp dir to the `user://` stream so `user://media`
        // resolves here (override=false prepends, so this path wins over the
        // real install's media folder). Accounts continue to load from the real
        // store, so createSuperAdmin() only mutates its user object in memory.
        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $locator->addPath('user', '', $this->tempDir, false);
        $locator->addPath('cache', '', $this->tempDir . '/cache', false);

        $this->grav['config']->set('plugins.api.route', '/api');
        $this->grav['config']->set('plugins.api.version_prefix', 'v1');
        $this->grav['config']->set('system.cache.enabled', false);
        // Keep the scan side-effect-free; we assert nothing EXIF-related.
        $this->grav['config']->set('system.media.auto_metadata_exif', false);
        // Make `rating` a filterable/sortable metadata field.
        $this->grav['config']->set('plugins.api.media_metadata.fields', [
            ['key' => 'rating', 'label' => 'Rating', 'type' => 'text'],
        ]);

        // Three images with distinct ratings. Manual order is deliberately
        // neither alphabetical nor rating order, so each ordering rule is
        // distinguishable in the output.
        $this->writeImage('a.png', 5);
        $this->writeImage('b.png', 2);
        $this->writeImage('c.png', 4);
        file_put_contents(
            $this->mediaDir . '/media_order.yaml',
            "media_order:\n  - c.png\n  - a.png\n  - b.png\n"
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        parent::tearDown();
    }

    public function testFilterReturnsOnlyMatchingFiles(): void
    {
        $response = $this->siteMedia(['filter' => 'rating:>=:3']);
        self::assertSame(200, $response->getStatusCode());

        // rating 5 (a) and 4 (c) match; rating 2 (b) is filtered out.
        self::assertSame(['c.png', 'a.png'], $this->filenames($response));
    }

    public function testSortOverridesManualOrder(): void
    {
        $response = $this->siteMedia(['sort' => 'rating', 'order' => 'desc']);
        self::assertSame(200, $response->getStatusCode());

        // Sorted by rating desc — a(5), c(4), b(2) — ignoring the c/a/b sidecar.
        self::assertSame(['a.png', 'c.png', 'b.png'], $this->filenames($response));
    }

    public function testFilterOnlyPreservesManualOrder(): void
    {
        // Same matching set as the filter test, but the assertion is about
        // order: the surviving files keep the sidecar's c-before-a order rather
        // than collapsing to alphabetical or rating order.
        $response = $this->siteMedia(['filter' => 'rating:in:4,5']);
        self::assertSame(['c.png', 'a.png'], $this->filenames($response));
    }

    public function testSearchCombinedWithFilterIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('search cannot be combined with filter or sort.');

        $this->siteMedia(['search' => 'png', 'filter' => 'rating:>=:3']);
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function siteMedia(array $query): \Psr\Http\Message\ResponseInterface
    {
        $controller = new MediaController($this->grav, $this->grav['config']);

        return $controller->siteMedia($this->makeRequest($query));
    }

    /** @return list<string> the `filename` of each returned media item, in order. */
    private function filenames(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true);

        return array_map(static fn(array $item) => $item['filename'], $body['data']);
    }

    /** Write a 1x1 PNG plus a `.meta.yaml` sidecar carrying a rating. */
    private function writeImage(string $name, int $rating): void
    {
        // Minimal valid 1x1 PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        file_put_contents($this->mediaDir . '/' . $name, $png);
        file_put_contents($this->mediaDir . '/' . $name . '.meta.yaml', "rating: {$rating}\n");
    }

    private function makeRequest(array $query): \Psr\Http\Message\ServerRequestInterface
    {
        $superAdmin = $this->createSuperAdmin();

        return new class ($query, $superAdmin) implements \Psr\Http\Message\ServerRequestInterface {
            private array $attributes;

            public function __construct(
                private readonly array $query,
                object $user,
            ) {
                $this->attributes = ['api_user' => $user];
            }

            public function getMethod(): string { return 'GET'; }
            public function getQueryParams(): array { return $this->query; }
            public function getServerParams(): array { return []; }
            public function getAttribute(string $name, mixed $default = null): mixed { return $this->attributes[$name] ?? $default; }
            public function getHeaderLine(string $name): string { return ''; }
            public function getHeader(string $name): array { return []; }
            public function hasHeader(string $name): bool { return false; }
            public function getHeaders(): array { return []; }
            public function getParsedBody(): mixed { return null; }
            public function getUploadedFiles(): array { return []; }

            public function withAttribute(string $name, mixed $value): static {
                $clone = clone $this;
                $clone->attributes[$name] = $value;
                return $clone;
            }

            public function getUri(): \Psr\Http\Message\UriInterface {
                return new class implements \Psr\Http\Message\UriInterface {
                    public function getScheme(): string { return 'https'; }
                    public function getAuthority(): string { return ''; }
                    public function getUserInfo(): string { return ''; }
                    public function getHost(): string { return 'localhost'; }
                    public function getPort(): ?int { return null; }
                    public function getPath(): string { return '/api/v1/media'; }
                    public function getQuery(): string { return ''; }
                    public function getFragment(): string { return ''; }
                    public function withScheme(string $scheme): static { return clone $this; }
                    public function withUserInfo(string $user, ?string $password = null): static { return clone $this; }
                    public function withHost(string $host): static { return clone $this; }
                    public function withPort(?int $port): static { return clone $this; }
                    public function withPath(string $path): static { return clone $this; }
                    public function withQuery(string $query): static { return clone $this; }
                    public function withFragment(string $fragment): static { return clone $this; }
                    public function __toString(): string { return '/api/v1/media'; }
                };
            }
            public function getBody(): \Psr\Http\Message\StreamInterface {
                return new class implements \Psr\Http\Message\StreamInterface {
                    public function __toString(): string { return ''; }
                    public function close(): void {}
                    public function detach() { return null; }
                    public function getSize(): ?int { return 0; }
                    public function tell(): int { return 0; }
                    public function eof(): bool { return true; }
                    public function isSeekable(): bool { return false; }
                    public function seek(int $offset, int $whence = SEEK_SET): void {}
                    public function rewind(): void {}
                    public function isWritable(): bool { return false; }
                    public function write(string $string): int { return 0; }
                    public function isReadable(): bool { return true; }
                    public function read(int $length): string { return ''; }
                    public function getContents(): string { return ''; }
                    public function getMetadata(?string $key = null): mixed { return null; }
                };
            }
            public function getRequestTarget(): string { return '/api/v1/media'; }
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
            public function withQueryParams(array $query): static {
                $clone = clone $this;
                return $clone;
            }
            public function withParsedBody($data): static { return clone $this; }
            public function getAttributes(): array { return $this->attributes; }
            public function withUploadedFiles(array $uploadedFiles): static { return clone $this; }
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

        // Grant the access in memory only (no save — the real accounts store is
        // never touched). isSuperAdmin() keys off access.api.super specifically,
        // which the stock admin account does not carry.
        $user->set('access', [
            'admin' => ['super' => true, 'login' => true],
            'api' => ['super' => true, 'access' => true, 'media' => ['read' => true, 'write' => true]],
        ]);

        return $user;
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
