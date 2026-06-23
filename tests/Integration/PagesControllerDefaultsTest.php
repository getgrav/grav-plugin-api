<?php

declare(strict_types=1);

// Load the API plugin's autoloader so its controller classes are available
require_once '/Users/rhuk/Projects/grav/grav-plugin-api/vendor/autoload.php';

use Codeception\Util\Fixtures;
use Grav\Common\Grav;
use Grav\Common\Page\Pages;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression coverage for getgrav/grav-plugin-admin2#49: creating a new page
 * through the API ignored the template blueprint's `default:` field values, so
 * a page declaring `header.published: false` went live the moment it was
 * created and any other defaults were dropped from the frontmatter.
 *
 * The create/translate endpoints now back-fill the blueprint's `header.*`
 * defaults underneath the submitted data (Grav 1.7 parity).
 */
class PagesControllerDefaultsTest extends \PHPUnit\Framework\TestCase
{
    protected Grav $grav;
    protected Pages $pages;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $grav = Fixtures::get('grav');
        $this->grav = $grav();

        $this->tempDir = sys_get_temp_dir() . '/grav_api_defaults_test_' . uniqid();
        @mkdir($this->tempDir . '/pages', 0775, true);
        @mkdir($this->tempDir . '/cache', 0775, true);

        @mkdir($this->tempDir . '/blueprints/pages', 0775, true);

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $locator->addPath('page', '', $this->tempDir . '/pages', false);
        $locator->addPath('cache', '', $this->tempDir . '/cache', false);
        // Make the custom template's blueprint resolvable via
        // blueprints://pages/blog-post.yaml.
        $locator->addPath('blueprints', '', $this->tempDir . '/blueprints', false);

        $this->grav['config']->set('plugins.api.route', '/api');
        $this->grav['config']->set('plugins.api.version_prefix', 'v1');
        // Disable on-disk caches so the page-type registry rebuilds against the
        // blueprint we register below rather than a stale snapshot.
        $this->grav['config']->set('system.cache.enabled', false);

        $this->registerBlogPostBlueprint();

        $this->pages = $this->grav['pages'];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        parent::tearDown();
    }

    public function testNewPagePicksUpBlueprintHeaderDefaults(): void
    {
        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/my-post',
            'title' => 'My Post',
            'template' => 'blog-post',
        ]);

        $response = $controller->create($request);
        self::assertSame(201, $response->getStatusCode());

        $header = $this->readFrontmatter($this->tempDir . '/pages/my-post/blog-post.md');

        self::assertSame('My Post', $header['title']);
        self::assertArrayHasKey('published', $header, 'published default must be written to frontmatter');
        self::assertFalse($header['published'], 'New page must inherit published: false from the blueprint');
        self::assertSame('from-blueprint', $header['body_classes'], 'Non-publishing defaults must also flow through');
    }

    public function testSubmittedValuesOverrideBlueprintDefaults(): void
    {
        $controller = $this->createPagesController();
        $request = $this->makeRequest('POST', '/api/v1/pages', [
            'route' => '/live-post',
            'title' => 'Live Post',
            'template' => 'blog-post',
            'header' => ['published' => true],
        ]);

        $response = $controller->create($request);
        self::assertSame(201, $response->getStatusCode());

        $header = $this->readFrontmatter($this->tempDir . '/pages/live-post/blog-post.md');

        self::assertTrue($header['published'], 'Explicit submitted value must win over the default');
        self::assertSame('from-blueprint', $header['body_classes'], 'Untouched defaults still apply');
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    /**
     * Write a `blog-post` page template whose blueprint sets `default:` values,
     * reachable via blueprints://pages/blog-post.yaml (the path is registered
     * in setUp). A bare test environment has no `theme://` stream, so
     * Pages::getTypes() returns an empty registry — but Blueprints::get() still
     * resolves the template straight off the blueprints stream.
     */
    private function registerBlogPostBlueprint(): void
    {
        file_put_contents($this->tempDir . '/blueprints/pages/blog-post.yaml', <<<'YAML'
title: Blog Post
form:
  fields:
    header.published:
      type: toggle
      default: false
    header.body_classes:
      type: text
      default: 'from-blueprint'
YAML);
    }

    /** @return array<string, mixed> */
    private function readFrontmatter(string $file): array
    {
        self::assertFileExists($file, "Expected the new page file to be written at {$file}");
        $raw = file_get_contents($file);
        if (preg_match('/^---\n(.*?)\n---/s', $raw, $m)) {
            return (array) Yaml::parse($m[1]);
        }

        return [];
    }

    private function createPagesController(): PagesController
    {
        return new PagesController($this->grav, $this->grav['config']);
    }

    private function makeRequest(string $method, string $path, array $body = [], array $routeParams = []): \Psr\Http\Message\ServerRequestInterface
    {
        $superAdmin = $this->createSuperAdmin();

        return new class ($method, $path, $body, $routeParams, $superAdmin) implements \Psr\Http\Message\ServerRequestInterface {
            private array $attributes;

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
            public function getUploadedFiles(): array { return []; }

            public function withAttribute(string $name, mixed $value): static {
                $clone = clone $this;
                $clone->attributes[$name] = $value;
                return $clone;
            }

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

        if (!$user->exists()) {
            $user->set('email', 'admin@test.com');
            $user->set('fullname', 'Test Admin');
            $user->set('state', 'enabled');
            $user->set('access', [
                'admin' => ['super' => true, 'login' => true],
                'api' => ['access' => true, 'pages' => ['read' => true, 'write' => true]],
            ]);
            $user->save();
        }

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
