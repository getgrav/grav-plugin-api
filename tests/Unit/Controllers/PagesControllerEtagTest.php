<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Controllers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Controllers\PagesController;
use Grav\Plugin\Api\Serializers\PageSerializer;
use Grav\Plugin\Api\Tests\Unit\TestHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression coverage for getgrav/grav-plugin-admin2#189.
 *
 * The admin's editor loads a page with `?translations=true`, and show() hashed
 * that response, translation data included, while update() hashed the page
 * without it. The ETag from the editor's own load therefore never matched, so
 * Admin2 could not send `If-Match` on a page save without every save failing
 * with a 409.
 *
 * The ETag is now a hash of what a save can overwrite (frontmatter, body,
 * template, language) and nothing else in the response. Otherwise a media
 * upload, a new child page or the mtime that Page::save() leaves stale on the
 * object in memory made the next save, or the ETag from the previous one, fail
 * with a 409 for a change the save would never have overwritten.
 */
#[CoversClass(PagesController::class)]
#[CoversClass(PageSerializer::class)]
class PagesControllerEtagTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
        parent::tearDown();
    }

    private function controller(): PagesController
    {
        $config = new Config([
            'plugins' => ['api' => ['route' => '/api', 'version_prefix' => 'v1']],
        ]);
        $locator = new class {
            public function findResource(string $uri, bool $absolute = false): string
            {
                return sys_get_temp_dir() . '/grav_api_etag_test';
            }
        };

        return new PagesController(TestHelper::createMockGrav(['config' => $config, 'locator' => $locator]), $config);
    }

    private function page(string $content = 'Original body.', int $modified = 1_700_000_000, array $header = ['title' => 'Etag page'], array $children = [], array $media = []): PageInterface
    {
        // The serializer calls a few methods of the page class that PageInterface lacks.
        $page = $this->createMockForIntersectionOfInterfaces([PageInterface::class, PageClassMethods::class]);
        $page->method('header')->willReturn((object) $header);
        $page->method('route')->willReturn('/etag-page');
        $page->method('rawRoute')->willReturn('/etag-page');
        $page->method('slug')->willReturn('etag-page');
        $page->method('children')->willReturn(new \ArrayIterator($children));
        $page->method('rawMarkdown')->willReturn($content);
        $page->method('media')->willReturn(new class ($media) {
            public function __construct(private readonly array $media) {}

            public function all(): array
            {
                return $this->media;
            }
        });
        $page->method('modified')->willReturn($modified);
        $page->method('date')->willReturn($modified);
        $page->method('translatedLanguages')->willReturn(['en' => '/etag-page', 'fr' => '/etag-page']);
        $page->method('untranslatedLanguages')->willReturn(['de']);

        return $page;
    }

    /** The response body for a read of the page made with these options. */
    private function read(PageInterface $page, array $options = []): array
    {
        return (new PageSerializer())->serialize($page, $options);
    }

    /** The ETag show(), update() and move() give a page they have serialized as `$data`. */
    private function etag(PageInterface $page, array $data): string
    {
        return (new ReflectionMethod(PagesController::class, 'pageEtag'))->invoke($this->controller(), $page, $data);
    }

    #[Test]
    public function the_editors_translations_read_has_the_etag_update_checks(): void
    {
        $page = $this->page();

        // update() hashes the plain serialization.
        $plain = $this->read($page);
        $withTranslations = $this->read($page, ['include_translations' => true]);

        // The translation data is really in the response, so hashing the
        // response as it stands is what used to make the two differ.
        self::assertArrayHasKey('translated_languages', $withTranslations);
        self::assertNotSame(md5((string) json_encode($plain)), md5((string) json_encode($withTranslations)));

        self::assertSame($this->etag($page, $plain), $this->etag($page, $withTranslations));
    }

    #[Test]
    public function a_read_that_adds_children_or_leaves_out_the_body_has_the_same_etag(): void
    {
        $page = $this->page();
        $expected = $this->etag($page, $this->read($page));

        // The rendered (`render=true`) and summary reads need Twig and
        // Utils::truncate(), which these stubs lack, so they were checked
        // against a live site instead.
        self::assertSame($expected, $this->etag($page, $this->read($page, ['include_children' => true, 'children_depth' => 2])));

        $withoutBody = $this->read($page, ['include_content' => false]);
        self::assertArrayNotHasKey('content', $withoutBody);
        self::assertSame($expected, $this->etag($page, $withoutBody));
    }

    #[Test]
    public function the_etag_follows_what_a_save_overwrites(): void
    {
        $page = $this->page();
        $etag = $this->etag($page, $this->read($page));

        $changed = [
            'body' => $this->page('Original body. Agent paragraph.'),
            'frontmatter' => $this->page(header: ['title' => 'Etag page', 'description' => 'Agent description.']),
        ];
        foreach ($changed as $what => $other) {
            self::assertNotSame($etag, $this->etag($other, $this->read($other)), "A different {$what}");
        }
    }

    #[Test]
    public function the_etag_ignores_what_a_save_cannot_overwrite(): void
    {
        $page = $this->page();
        $etag = $this->etag($page, $this->read($page));

        // Page::save() leaves the in-memory mtime at its pre-save value, so a
        // PATCH response and the next request disagree on it.
        $saved = $this->page(modified: 1_700_000_500);
        self::assertSame($etag, $this->etag($saved, $this->read($saved)));

        // Uploading a media file, or adding a child page, changes the page's
        // response but not the file a save writes.
        $medium = new class {
            public string $filename = 'photo.jpg';

            public function get(string $key): string
            {
                return $key === 'mime' ? 'image/jpeg' : '1024';
            }
        };
        $grown = $this->page(children: [$this->page()], media: ['photo.jpg' => $medium]);
        $data = $this->read($grown);
        self::assertTrue($data['has_children']);
        self::assertSame($etag, $this->etag($grown, $data));

        // A body saved with CRLF reads back with LF.
        $crlf = $this->page("Line one.\r\nLine two.");
        $lf = $this->page("Line one.\nLine two.");
        self::assertSame($this->etag($lf, $this->read($lf)), $this->etag($crlf, $this->read($crlf)));
    }
}

/** What the serializer reads from the page class that PageInterface leaves out. */
interface PageClassMethods
{
    public function rawMarkdown(?string $var = null): string;

    public function media(): object;

    /** @return array<string, string> */
    public function translatedLanguages(): array;

    /** @return list<string> */
    public function untranslatedLanguages(): array;
}
