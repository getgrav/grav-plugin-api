<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Page\Interfaces\PageInterface;

/**
 * A page has two names that are often the same and sometimes not. Its file
 * type is the name of its content file (`item` for `item.md` or `item.de.md`),
 * which every language file of the page shares. Its template is what core
 * renders it with: the `template` header when there is one, the file type
 * otherwise. `item.md` with `template: blogpost` renders with `blogpost`, but
 * its German translation is still `item.de.md` (admin2#201).
 */
final class PageTemplate
{
    /**
     * The template core renders the page with.
     *
     * `Page::template()` answers this, except for a Flex-indexed page in a
     * listing, whose header can be empty: it then falls back to the file type.
     * The header is read from the file in that case, the same fallback the
     * serializer uses for published, visible and title.
     *
     * @param array<string, mixed>|null $header the page's frontmatter, when the caller already has it
     */
    public static function of(PageInterface $page, ?array $header = null): string
    {
        $header ??= self::header($page);
        $template = $header['template'] ?? null;
        if (is_string($template) && trim($template) !== '') {
            return trim($template);
        }

        return (string) $page->template();
    }

    /**
     * The name of the page's content file without its language and extension,
     * as core builds the template fallback: `item` for `item.md`, `item.de.md`
     * and for a module's `item.md` (whose template() is `modular/item`).
     */
    public static function fileType(PageInterface $page): string
    {
        $name = (string) $page->name();
        $extension = (string) $page->extension();
        if ($name !== '' && $extension !== '' && str_ends_with($name, $extension)) {
            $type = substr($name, 0, -strlen($extension));
            if ($type !== '') {
                return $type;
            }
        }

        return basename((string) $page->template());
    }

    /** @return array<string, mixed> */
    private static function header(PageInterface $page): array
    {
        $header = $page->header();
        $header = $header === null ? [] : (json_decode((string) json_encode($header), true) ?: []);

        if ($header === [] && $page instanceof \Grav\Framework\Flex\Pages\FlexPageObject) {
            $header = FrontmatterReader::forPage($page);
        }

        return $header;
    }
}
