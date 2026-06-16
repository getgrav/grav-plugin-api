<?php

declare(strict_types=1);

// Load the API plugin's autoloader so its controller classes are available
require_once '/Users/rhuk/Projects/grav/grav-plugin-api/vendor/autoload.php';

use Codeception\Util\Fixtures;
use Grav\Common\Grav;
use Grav\Common\Language\Language;
use Grav\Common\Page\Pages;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;

/**
 * Integration tests pinning multilingual page resolution for GET/PATCH/DELETE.
 *
 * Regression coverage for getgrav/grav-plugin-api#6: a PATCH carrying
 * ?lang=<secondary> used to clobber the default-language file (and a DELETE
 * 404'd) because Grav builds and caches the pages index for whichever language
 * is active at init time. The controller set the active language but never
 * forced the index to rebuild, so find()/save() resolved to the wrong
 * translation file. The fix rebuilds pages whenever applyLanguage() changes the
 * active language.
 */
class PagesControllerLanguageTest extends \PHPUnit\Framework\TestCase
{
    protected Grav $grav;
    protected Pages $pages;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $grav = Fixtures::get('grav');
        $this->grav = $grav();

        $this->tempDir = sys_get_temp_dir() . '/grav_api_lang_test_' . uniqid();
        @mkdir($this->tempDir . '/pages', 0775, true);
        @mkdir($this->tempDir . '/cache', 0775, true);

        /** @var UniformResourceLocator $locator */
        $locator = $this->grav['locator'];
        $locator->addPath('page', '', $this->tempDir . '/pages', false);
        $locator->addPath('cache', '', $this->tempDir . '/cache', false);

        // API config
        $this->grav['config']->set('plugins.api.route', '/api');
        $this->grav['config']->set('plugins.api.version_prefix', 'v1');

        // Turn this install into a multilingual site: en (default) + fr, with
        // the default language stored in a suffix-less file (default.md).
        $this->grav['config']->set('system.languages.supported', ['en', 'fr']);
        $this->grav['config']->set('system.languages.default_lang', 'en');
        $this->grav['config']->set('system.languages.include_default_lang', false);
        // Disable the on-disk pages cache so each rebuild reads the fixtures we
        // just wrote rather than a stale per-language snapshot.
        $this->grav['config']->set('system.cache.enabled', false);

        // The Language service reads system.languages.supported in its
        // constructor, so rebuild it now that the config is multilingual.
        unset($this->grav['language']);
        /** @var Language $language */
        $language = $this->grav['language'];
        self::assertTrue($language->enabled(), 'Multi-language should be enabled for these tests');

        $this->pages = $this->grav['pages'];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tempDir);
        parent::tearDown();
    }

    public function testPatchWithLangUpdatesTheTargetTranslationNotTheDefault(): void
    {
        $this->createMultilangPage('contact', [
            'en' => "title: Contact\n",
            'fr' => "title: Contactez\n",
        ], [
            'en' => 'English body',
            'fr' => 'Corps francais',
        ]);

        $controller = $this->createPagesController();
        $request = $this->makeRequest(
            'PATCH',
            '/api/v1/pages/contact',
            ['content' => 'Nouveau corps francais'],
            ['route' => 'contact'],
            ['lang' => 'fr'],
        );

        $response = $controller->update($request);
        self::assertSame(200, $response->getStatusCode());

        $frFile = $this->tempDir . '/pages/contact/default.fr.md';
        $enFile = $this->tempDir . '/pages/contact/default.md';

        self::assertStringContainsString('Nouveau corps francais', file_get_contents($frFile), 'French file should be updated');
        self::assertStringContainsString('English body', file_get_contents($enFile), 'Default (English) file must be untouched');
        self::assertStringNotContainsString('Nouveau corps francais', file_get_contents($enFile), 'Default file must not receive the French payload');
    }

    public function testPatchWithoutLangUpdatesTheDefaultTranslation(): void
    {
        $this->createMultilangPage('about', [
            'en' => "title: About\n",
            'fr' => "title: A propos\n",
        ], [
            'en' => 'English body',
            'fr' => 'Corps francais',
        ]);

        $controller = $this->createPagesController();
        $request = $this->makeRequest(
            'PATCH',
            '/api/v1/pages/about',
            ['content' => 'Updated english body'],
            ['route' => 'about'],
        );

        $response = $controller->update($request);
        self::assertSame(200, $response->getStatusCode());

        $enFile = $this->tempDir . '/pages/about/default.md';
        $frFile = $this->tempDir . '/pages/about/default.fr.md';

        self::assertStringContainsString('Updated english body', file_get_contents($enFile), 'Default file should be updated');
        self::assertStringContainsString('Corps francais', file_get_contents($frFile), 'French file must be untouched');
    }

    public function testDeleteWithLangRemovesOnlyThatTranslation(): void
    {
        $this->createMultilangPage('news', [
            'en' => "title: News\n",
            'fr' => "title: Nouvelles\n",
        ], [
            'en' => 'English body',
            'fr' => 'Corps francais',
        ]);

        $controller = $this->createPagesController();
        $request = $this->makeRequest(
            'DELETE',
            '/api/v1/pages/news',
            [],
            ['route' => 'news'],
            ['lang' => 'fr'],
        );

        $response = $controller->delete($request);
        self::assertSame(204, $response->getStatusCode());

        self::assertFileDoesNotExist($this->tempDir . '/pages/news/default.fr.md', 'French translation should be deleted');
        self::assertFileExists($this->tempDir . '/pages/news/default.md', 'Default translation must survive');
    }

    // =========================================================================
    // Helper methods
    // =========================================================================

    private function createPagesController(): PagesController
    {
        return new PagesController($this->grav, $this->grav['config']);
    }

    /**
     * Write a page folder with a suffix-less default.md for the default
     * language and default.<lang>.md for every secondary language.
     *
     * @param array<string, string> $headers   lang => frontmatter body (YAML)
     * @param array<string, string> $contents  lang => markdown body
     */
    private function createMultilangPage(string $slug, array $headers, array $contents): void
    {
        $dir = $this->tempDir . '/pages/' . $slug;
        @mkdir($dir, 0775, true);

        $default = $this->grav['language']->getDefault();
        foreach ($headers as $lang => $frontmatter) {
            $name = $lang === $default ? 'default.md' : "default.{$lang}.md";
            file_put_contents($dir . '/' . $name, "---\n{$frontmatter}---\n" . ($contents[$lang] ?? ''));
        }

        $this->pages->reset();
        $this->pages->init();
    }

    private function makeRequest(
        string $method,
        string $path,
        array $body = [],
        array $routeParams = [],
        array $query = [],
    ): \Psr\Http\Message\ServerRequestInterface {
        $superAdmin = $this->createSuperAdmin();

        return new class ($method, $path, $body, $routeParams, $query, $superAdmin) implements \Psr\Http\Message\ServerRequestInterface {
            private array $attributes;

            public function __construct(
                private readonly string $method,
                private readonly string $path,
                private readonly array $body,
                array $routeParams,
                private readonly array $query,
                object $user,
            ) {
                $this->attributes = [
                    'api_user' => $user,
                    'json_body' => $body,
                    'route_params' => $routeParams,
                ];
            }

            public function getMethod(): string { return $this->method; }
            public function getQueryParams(): array { return $this->query; }
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
