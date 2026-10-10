<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Tests\Unit\Serializers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Api\Serializers\PageSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A language whose translation is a draft is listed as translated and never as
 * untranslated too (#57). Grav's two helpers disagree about drafts by default:
 * translatedLanguages() counts them, untranslatedLanguages() does not.
 */
#[CoversClass(PageSerializer::class)]
class PageSerializerDraftTranslationTest extends TestCase
{
    protected function tearDown(): void
    {
        Grav::resetInstance();
    }

    #[Test]
    public function a_draft_translation_is_in_one_list_only(): void
    {
        $page = $this->createMockForIntersectionOfInterfaces([PageInterface::class, DraftTranslationPage::class]);
        $page->method('header')->willReturn(new \stdClass());
        $page->method('children')->willReturn(new \ArrayIterator([]));
        $page->method('published')->willReturn(false);
        // `en` is a draft, `fr` doesn't exist, answered the way Grav's Page does.
        $page->method('translatedLanguages')->willReturnCallback(
            static fn (bool $onlyPublished = false): array => $onlyPublished ? [] : ['en' => '/my-new-page']
        );
        $page->method('untranslatedLanguages')->willReturnCallback(
            static fn (bool $includeUnpublished = false): array => $includeUnpublished ? ['fr'] : ['en', 'fr']
        );

        $data = (new PageSerializer())->serialize($page, [
            'include_content' => false,
            'include_media' => false,
            'include_template_state' => false,
            'include_translations' => true,
        ]);

        self::assertSame(['en' => '/my-new-page'], $data['translated_languages']);
        self::assertSame(['fr'], $data['untranslated_languages']);
    }
}

/** The page class's translation helpers, with parameter names unlike Grav's. */
interface DraftTranslationPage
{
    /** @return array<string, string> */
    public function translatedLanguages(bool $published = false): array;

    /** @return list<string> */
    public function untranslatedLanguages(bool $drafts = false): array;
}
