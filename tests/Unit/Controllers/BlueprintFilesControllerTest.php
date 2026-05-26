<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Framework\Acl\Permissions;
use Grav\Plugin\Api\Controllers\BlueprintFilesController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Coverage for the read-only blueprint-files browse endpoint. Mirrors the
 * stream-resolution and security guarantees of BlueprintUploadController but
 * for `folder:` (read) rather than `destination:` (write).
 */
#[CoversClass(BlueprintFilesController::class)]
class BlueprintFilesControllerTest extends TestCase
{
    private string $tempDir;
    private Config $config;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/grav_api_blueprint_files_' . uniqid();
        mkdir($this->tempDir . '/accounts', 0775, true);
        mkdir($this->tempDir . '/media', 0775, true);
        mkdir($this->tempDir . '/cache', 0775, true);
        mkdir($this->tempDir . '/plugins/api', 0775, true);
        mkdir($this->tempDir . '/themes/quark/images', 0775, true);

        $this->config = new Config([
            'system' => ['pages' => ['theme' => 'quark']],
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
    }

    /**
     * Minimal real PNG bytes so mime_content_type() reports image/png.
     */
    private const PNG_BYTES = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\r\n-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    #[Test]
    public function user_media_stream_resolves_and_lists_files(): void
    {
        file_put_contents($this->tempDir . '/media/cover.png', self::PNG_BYTES);
        file_put_contents($this->tempDir . '/media/doc.pdf', '%PDF-1.4');

        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'user://media', ''));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        $names = array_column($body['data'], 'filename');
        sort($names);
        self::assertSame(['cover.png', 'doc.pdf'], $names);
        self::assertTrue($body['meta']['exists']);
    }

    #[Test]
    public function theme_stream_resolves(): void
    {
        file_put_contents($this->tempDir . '/themes/quark/images/logo.png', "\x89PNG");

        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'theme://images', 'themes/quark'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(['logo.png'], array_column($body['data'], 'filename'));
    }

    #[Test]
    public function self_scope_resolves_for_plugin(): void
    {
        mkdir($this->tempDir . '/plugins/api/assets', 0775, true);
        file_put_contents($this->tempDir . '/plugins/api/assets/icon.svg', '<svg/>');

        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'self@:assets', 'plugins/api'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(['icon.svg'], array_column($body['data'], 'filename'));
    }

    #[Test]
    public function self_literal_returns_page_media_sentinel(): void
    {
        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', '@self', ''));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('PAGE_MEDIA_ONLY', $body['data']['error']);
    }

    #[Test]
    public function self_at_literal_returns_page_media_sentinel(): void
    {
        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'self@', ''));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('PAGE_MEDIA_ONLY', $body['data']['error']);
    }

    #[Test]
    public function traversal_in_relative_path_is_rejected(): void
    {
        $controller = $this->buildController('alice');
        $this->expectException(ValidationException::class);
        $controller->list($this->listRequest('alice', '../etc', ''));
    }

    #[Test]
    public function traversal_in_stream_path_is_rejected(): void
    {
        $controller = $this->buildController('alice');
        $this->expectException(ValidationException::class);
        $controller->list($this->listRequest('alice', 'user://media/../../etc', ''));
    }

    #[Test]
    public function missing_folder_param_is_rejected(): void
    {
        $controller = $this->buildController('alice');
        $this->expectException(ValidationException::class);
        $controller->list($this->listRequest('alice', '', ''));
    }

    #[Test]
    public function non_existent_folder_returns_empty_with_exists_false(): void
    {
        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'user://does-not-exist', ''));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertFalse($body['meta']['exists']);
    }

    #[Test]
    public function accept_extension_filter_applies(): void
    {
        file_put_contents($this->tempDir . '/media/a.png', self::PNG_BYTES);
        file_put_contents($this->tempDir . '/media/b.pdf', '%PDF');
        file_put_contents($this->tempDir . '/media/c.zip', 'PK');

        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'user://media', '', '.png,.pdf'));

        $names = array_column($this->jsonBody($response)['data'], 'filename');
        sort($names);
        self::assertSame(['a.png', 'b.pdf'], $names);
    }

    #[Test]
    public function accept_mime_wildcard_filter_applies(): void
    {
        file_put_contents($this->tempDir . '/media/a.png', self::PNG_BYTES);
        file_put_contents($this->tempDir . '/media/b.pdf', '%PDF');

        $controller = $this->buildController('alice');
        $response = $controller->list($this->listRequest('alice', 'user://media', '', 'image/*'));

        $names = array_column($this->jsonBody($response)['data'], 'filename');
        self::assertSame(['a.png'], $names);
    }

    #[Test]
    public function users_scope_cross_user_is_forbidden_for_low_priv_caller(): void
    {
        $controller = $this->buildController('alice');
        $this->expectException(ForbiddenException::class);
        $controller->list($this->listRequest('alice', 'self@:', 'users/bob'));
    }

    private function buildController(string $username): BlueprintFilesController
    {
        $user = TestHelper::createMockUser($username, [
            'access' => ['api' => ['access' => true, 'media' => ['read' => true]]],
        ]);

        TestHelper::createMockGrav([
            'config' => $this->config,
            'locator' => new BlueprintFilesTestLocator($this->tempDir),
            'uri' => new class {
                public function rootUrl(): string { return 'https://example.test'; }
            },
            'permissions' => new Permissions(),
            'accounts' => TestHelper::createMockAccounts([$username => $user]),
        ]);

        // Subclass that swaps the Grav Media iterator for a filesystem scan
        // returning duck-typed Medium objects. Real Grav Media needs streams,
        // image cache, taxonomies, and other framework state we don't want
        // to spin up for a unit test.
        return new class (\Grav\Common\Grav::instance(), $this->config) extends BlueprintFilesController {
            protected function iterateMedia(string $absoluteDir): iterable
            {
                foreach (scandir($absoluteDir) ?: [] as $name) {
                    if ($name === '.' || $name === '..') continue;
                    $full = $absoluteDir . '/' . $name;
                    if (!is_file($full)) continue;
                    yield $name => new BlueprintFilesTestMedium($name, $full);
                }
            }
        };
    }

    private function listRequest(
        string $username,
        string $folder,
        string $scope,
        string $accept = '',
    ): ServerRequestInterface {
        $user = TestHelper::createMockUser($username, [
            'access' => ['api' => ['access' => true, 'media' => ['read' => true]]],
        ]);

        return new BlueprintFilesTestRequest(
            ['folder' => $folder, 'scope' => $scope, 'accept' => $accept],
            ['api_user' => $user],
        );
    }

    private function jsonBody(ResponseInterface $r): array
    {
        return json_decode((string) $r->getBody(), true) ?? [];
    }

    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $this->rmrf($path . '/' . $item);
        }
        rmdir($path);
    }
}

final class BlueprintFilesTestMedium
{
    public function __construct(
        public readonly string $filename,
        private readonly string $absolutePath,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === 'mime') return mime_content_type($this->absolutePath) ?: 'application/octet-stream';
        if ($key === 'size') return filesize($this->absolutePath) ?: 0;
        return $default;
    }

    public function url(): string { return 'file://' . $this->absolutePath; }
    public function path(): string { return $this->absolutePath; }
    public function modified(): int { return filemtime($this->absolutePath) ?: 0; }
}

final class BlueprintFilesTestLocator
{
    public function __construct(private readonly string $base) {}

    public function isStream(string $path): bool
    {
        return preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $path) === 1;
    }

    public function findResource(string $uri, bool $absolute = false, bool $first = false): string|false
    {
        // Modeled on Grav's UniformResourceLocator: the third arg is `$first`
        // (return first match), not "create dir". Read-only browse must not
        // silently mkdir non-existent destinations.
        $map = [
            'user://' => $this->base,
            'cache://' => $this->base . '/cache',
            'account://' => $this->base . '/accounts',
            'plugins://' => $this->base . '/plugins',
            'themes://' => $this->base . '/themes',
            'theme://' => $this->base . '/themes/quark',
            'image://' => $this->base . '/images',
            'asset://' => $this->base . '/assets',
            'page://' => $this->base . '/pages',
        ];

        foreach ($map as $prefix => $root) {
            if (str_starts_with($uri, $prefix)) {
                return rtrim($root . '/' . ltrim(substr($uri, strlen($prefix)), '/'), '/');
            }
        }
        return false;
    }
}

final class BlueprintFilesTestRequest implements ServerRequestInterface
{
    public function __construct(
        private readonly array $queryParams,
        private array $attributes,
    ) {}

    public function getQueryParams(): array { return $this->queryParams; }
    public function getAttribute(string $name, mixed $default = null): mixed { return $this->attributes[$name] ?? $default; }
    public function withAttribute(string $name, mixed $value): static { $clone = clone $this; $clone->attributes[$name] = $value; return $clone; }
    public function withoutAttribute(string $name): static { $clone = clone $this; unset($clone->attributes[$name]); return $clone; }
    public function getAttributes(): array { return $this->attributes; }
    public function getMethod(): string { return 'GET'; }
    public function withMethod(string $method): static { return clone $this; }
    public function getParsedBody(): mixed { return null; }
    public function getUploadedFiles(): array { return []; }
    public function getBody(): StreamInterface { return new BlueprintFilesTestStream(''); }
    public function getHeaderLine(string $name): string { return ''; }
    public function getHeader(string $name): array { return []; }
    public function getHeaders(): array { return []; }
    public function hasHeader(string $name): bool { return false; }
    public function getRequestTarget(): string { return '/api/v1/blueprint-files'; }
    public function withRequestTarget(string $requestTarget): static { return clone $this; }
    public function getUri(): UriInterface { return new BlueprintFilesTestUri(); }
    public function withUri(UriInterface $uri, bool $preserveHost = false): static { return clone $this; }
    public function getProtocolVersion(): string { return '1.1'; }
    public function withProtocolVersion(string $version): static { return clone $this; }
    public function withHeader(string $name, $value): static { return clone $this; }
    public function withAddedHeader(string $name, $value): static { return clone $this; }
    public function withoutHeader(string $name): static { return clone $this; }
    public function withBody(StreamInterface $body): static { return clone $this; }
    public function getServerParams(): array { return []; }
    public function getCookieParams(): array { return []; }
    public function withCookieParams(array $cookies): static { return clone $this; }
    public function withQueryParams(array $query): static { return clone $this; }
    public function withUploadedFiles(array $uploadedFiles): static { return clone $this; }
    public function withParsedBody($data): static { return clone $this; }
}

final class BlueprintFilesTestStream implements StreamInterface
{
    public function __construct(private readonly string $contents) {}
    public function __toString(): string { return $this->contents; }
    public function close(): void {}
    public function detach() { return null; }
    public function getSize(): ?int { return strlen($this->contents); }
    public function tell(): int { return 0; }
    public function eof(): bool { return true; }
    public function isSeekable(): bool { return false; }
    public function seek(int $offset, int $whence = SEEK_SET): void {}
    public function rewind(): void {}
    public function isWritable(): bool { return false; }
    public function write(string $string): int { return 0; }
    public function isReadable(): bool { return true; }
    public function read(int $length): string { return $this->contents; }
    public function getContents(): string { return $this->contents; }
    public function getMetadata(?string $key = null): mixed { return null; }
}

final class BlueprintFilesTestUri implements UriInterface
{
    public function getScheme(): string { return 'https'; }
    public function getAuthority(): string { return 'example.test'; }
    public function getUserInfo(): string { return ''; }
    public function getHost(): string { return 'example.test'; }
    public function getPort(): ?int { return null; }
    public function getPath(): string { return '/api/v1/blueprint-files'; }
    public function getQuery(): string { return ''; }
    public function getFragment(): string { return ''; }
    public function withScheme(string $scheme): static { return clone $this; }
    public function withUserInfo(string $user, ?string $password = null): static { return clone $this; }
    public function withHost(string $host): static { return clone $this; }
    public function withPort(?int $port): static { return clone $this; }
    public function withPath(string $path): static { return clone $this; }
    public function withQuery(string $query): static { return clone $this; }
    public function withFragment(string $fragment): static { return clone $this; }
    public function __toString(): string { return 'https://example.test/api/v1/blueprint-files'; }
}
