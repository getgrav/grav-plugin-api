<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\Page\Interfaces\PageInterface;

class PageSerializer implements SerializerInterface
{
    public function __construct(
        private ?MediaSerializer $mediaSerializer = null,
    ) {}

    public function serialize(object $resource, array $options = []): array
    {
        /** @var PageInterface $resource */
        $includeContent = $options['include_content'] ?? true;
        $renderContent = $options['render_content'] ?? false;
        $includeChildren = $options['include_children'] ?? false;
        $childrenDepth = $options['children_depth'] ?? 1;
        $includeMedia = $options['include_media'] ?? true;

        $includeTranslations = $options['include_translations'] ?? false;

        $headerArr = $this->serializeHeader($resource->header());

        // Flex-indexed PageObject instances expose EMPTY headers during
        // listing (the index only materializes summary fields). That makes
        // $resource->published() / visible() fall back to Grav's default
        // "true" even when the frontmatter explicitly says false. Swap in the
        // fully-loaded legacy Page so every downstream field reads correctly.
        // Flex-indexed PageObject instances expose EMPTY headers during
        // listing (the index only materializes summary fields). Read the
        // frontmatter directly from the .md file so published/visible and
        // everything else in the header are accurate regardless of which
        // controller path we came through.
        if (empty($headerArr) && $resource instanceof \Grav\Framework\Flex\Pages\FlexPageObject) {
            $path = method_exists($resource, 'path') ? $resource->path() : null;
            $template = $resource->template();
            if ($path && $template) {
                $candidates = [];
                // Prefer the page's own language, then the active language,
                // then the untyped default, then any matching {template}*.md.
                $pageLang = $resource->language();
                if ($pageLang) {
                    $candidates[] = $path . '/' . $template . '.' . $pageLang . '.md';
                }
                $grav = \Grav\Common\Grav::instance();
                $lang = $grav['language'] ?? null;
                if ($lang && method_exists($lang, 'getLanguage')) {
                    $active = $lang->getLanguage();
                    if ($active) {
                        $candidates[] = $path . '/' . $template . '.' . $active . '.md';
                    }
                }
                $candidates[] = $path . '/' . $template . '.md';
                foreach ($candidates as $file) {
                    if (is_file($file)) {
                        $parsed = $this->parseFrontmatter($file);
                        if (!empty($parsed)) {
                            $headerArr = $parsed;
                            break;
                        }
                    }
                }
                // Fallback: glob for any {template}*.md file in the directory
                if (empty($headerArr)) {
                    foreach (glob($path . '/' . $template . '*.md') ?: [] as $file) {
                        $parsed = $this->parseFrontmatter($file);
                        if (!empty($parsed)) {
                            $headerArr = $parsed;
                            break;
                        }
                    }
                }
            }
        }

        $data = [
            'route' => $resource->route(),
            // Structural route — for the home page, route() returns the
            // public alias '/' but rawRoute() returns the actual page like
            // '/home'. Clients editing/finding pages should prefer this.
            'raw_route' => $resource->rawRoute(),
            'slug' => $resource->slug(),
            'title' => $resource->title(),
            'menu' => $resource->menu(),
            'template' => $resource->template(),
            'language' => $resource->language(),
            'header' => $headerArr,
            'taxonomy' => $resource->taxonomy(),
            // Prefer the explicit frontmatter value for published/visible over
            // the object method. During flex-indexed collection listings
            // (GET /pages) the indexed PageObject::published() can return a
            // stale/default "true" when the header isn't fully materialized,
            // while GET /pages/{route} goes through enablePages() and reads a
            // legacy Page where the method is correct. Reading the serialized
            // header array (same one we return to the client) gives the same
            // answer in both paths.
            'published' => array_key_exists('published', $headerArr) ? (bool)$headerArr['published'] : $resource->published(),
            'visible' => array_key_exists('visible', $headerArr) ? (bool)$headerArr['visible'] : $resource->visible(),
            'routable' => $resource->routable(),
            'date' => $this->formatTimestamp($resource->date()),
            'modified' => $this->formatTimestamp($resource->modified()),
            'order' => $resource->order(),
            'has_children' => count($resource->children()) > 0,
        ];

        if ($includeTranslations) {
            $data['translated_languages'] = $resource->translatedLanguages();
            $data['untranslated_languages'] = $resource->untranslatedLanguages();

            // Disambiguate Grav's translated_languages response: when the page
            // has an untyped base file (e.g. default.md), Grav reports every
            // site language as "translated" because default.md acts as a
            // fallback for any active lang. These two fields let admin UIs
            // tell whether each language is backed by an EXPLICIT file
            // (default.<lang>.md) or by the implicit default.md fallback.
            $pagePath = $resource->path();
            $template = $resource->template();
            $data['has_default_file'] = $pagePath && $template
                ? is_file($pagePath . '/' . $template . '.md')
                : false;

            // List of language codes that have a concrete `{template}.{lang}.md`
            // file on disk. Everything else in translated_languages is falling
            // back to default.md. Empty array when multilang is off.
            $explicit = [];
            if ($pagePath && $template) {
                $lang = \Grav\Common\Grav::instance()['language'] ?? null;
                $langCodes = $lang && method_exists($lang, 'getLanguages')
                    ? (array) $lang->getLanguages()
                    : [];
                foreach ($langCodes as $code) {
                    if (is_file($pagePath . '/' . $template . '.' . $code . '.md')) {
                        $explicit[] = $code;
                    }
                }
            }
            $data['explicit_language_files'] = $explicit;
        }

        if ($includeContent) {
            $data['content'] = $resource->rawMarkdown();
        }

        if ($renderContent) {
            $data['content_html'] = $resource->content();
        }

        $includeSummary = $options['include_summary'] ?? false;
        if ($includeSummary) {
            $summarySize = $options['summary_size'] ?? null;
            // summary() runs the page through the full Twig / shortcode pipeline,
            // so any page with a plugin shortcode whose dependencies aren't
            // available in the API request context (e.g. a `[poll]` that wants
            // the frontend theme's Twig env) can throw — we don't want that to
            // take down the whole response for something the client is treating
            // as a preview. Fall back to a plain-text rendering of the raw
            // markdown, trimmed to the requested size.
            try {
                $data['summary'] = $summarySize
                    ? $resource->summary($summarySize)
                    : $resource->summary();
            } catch (\Throwable $e) {
                $raw = (string) $resource->rawMarkdown();
                // Strip frontmatter artifacts, shortcodes, markdown syntax.
                $plain = preg_replace('/\[[^\]]+\s*\/?\]/', '', $raw) ?? $raw;
                $plain = preg_replace('/[#*_`>]/', '', $plain) ?? $plain;
                $plain = trim(preg_replace('/\s+/', ' ', $plain) ?? $plain);
                $max = $summarySize ?: 300;
                $data['summary'] = mb_strlen($plain) > $max
                    ? rtrim(mb_substr($plain, 0, $max)) . '…'
                    : $plain;
            }
        }

        if ($includeMedia) {
            $data['media'] = $this->serializeMedia($resource);
        }

        if ($includeChildren && $childrenDepth > 0) {
            $data['children'] = $this->serializeChildren(
                $resource,
                $options,
                $childrenDepth,
            );
        }

        return $data;
    }

    /**
     * Parse the YAML frontmatter from a Grav .md file. Returns the header
     * array, or empty array if there's no frontmatter / on parse failure.
     */
    private function parseFrontmatter(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            return [];
        }
        // Grav frontmatter: content between leading `---\n` and the next `---\n`.
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $contents, $m)) {
            return [];
        }
        try {
            $parsed = \Symfony\Component\Yaml\Yaml::parse($m[1]);
            return is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Serialize a collection of pages.
     */
    public function serializeCollection(iterable $pages, array $options = []): array
    {
        $result = [];

        foreach ($pages as $page) {
            $result[] = $this->serialize($page, $options);
        }

        return $result;
    }

    /**
     * Convert page header object to an associative array.
     */
    private function serializeHeader(object|null $header): array
    {
        if ($header === null) {
            return [];
        }

        return json_decode(json_encode($header), true) ?: [];
    }

    /**
     * Serialize the media collection attached to a page.
     */
    private function serializeMedia(PageInterface $page): array
    {
        $media = $page->media();

        if ($this->mediaSerializer) {
            return $this->mediaSerializer->serializeCollection($media->all());
        }

        $result = [];
        foreach ($media->all() as $filename => $medium) {
            $result[] = [
                'filename' => $medium->filename,
                'type' => $medium->get('mime'),
                'size' => $medium->get('size'),
            ];
        }

        return $result;
    }

    /**
     * Recursively serialize children pages up to the specified depth.
     */
    private function serializeChildren(PageInterface $page, array $options, int $depth): array
    {
        $childOptions = array_merge($options, [
            'include_children' => $depth > 1,
            'children_depth' => $depth - 1,
        ]);

        $result = [];

        foreach ($page->children() as $child) {
            $result[] = $this->serialize($child, $childOptions);
        }

        return $result;
    }

    /**
     * Format a Unix timestamp as ISO 8601.
     */
    private function formatTimestamp(int|null $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === 0) {
            return null;
        }

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeImmutable::ATOM);
    }
}
