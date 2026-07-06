<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Serializers;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Common\Grav;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Markdown\ParsedownExtra;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Markdown\Excerpts;
use Grav\Common\Utils;

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

        // For flex-indexed PageObject listings the in-memory header is empty
        // (we re-parsed the .md file into $headerArr above). $resource->title()
        // and ->menu() in that mode fall back to a slug-derived label
        // ("Contact-us") even when the frontmatter has a real title.
        // Prefer the parsed-header value so the listing reads the same as
        // the detail endpoint.
        $headerTitle = $headerArr['title'] ?? null;
        $headerMenu = $headerArr['menu'] ?? null;

        $data = [
            'route' => $resource->route(),
            // Structural route — for the home page, route() returns the
            // public alias '/' but rawRoute() returns the actual page like
            // '/home'. Clients editing/finding pages should prefer this.
            'raw_route' => $resource->rawRoute(),
            'slug' => $resource->slug(),
            // The on-disk folder basename, including any numeric ordering
            // prefix (e.g. `01.consulting`). `slug` is the prefix-stripped
            // name; admin UIs need the real folder to show/diagnose ordering.
            'folder' => $resource->folder(),
            'title' => is_string($headerTitle) && $headerTitle !== '' ? $headerTitle : $resource->title(),
            'menu' => is_string($headerMenu) && $headerMenu !== '' ? $headerMenu : (is_string($headerTitle) && $headerTitle !== '' ? $headerTitle : $resource->menu()),
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
            $data['summary'] = $this->buildTextSummary(
                $resource,
                is_int($summarySize) ? $summarySize : null,
            );
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
     *
     * Accepts mixed input because Page::modified()/date() return whatever was
     * set in frontmatter: normally an int (filemtime), but a legacy string
     * `modified:` value such as '2022-01-21 20:51:36' passes through verbatim.
     * Numeric strings are treated as Unix timestamps; other strings are parsed.
     */
    private function formatTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp === null || $timestamp === 0 || $timestamp === '') {
            return null;
        }

        if (is_string($timestamp) && !is_numeric($timestamp)) {
            $parsed = strtotime($timestamp);
            if ($parsed === false) {
                return null;
            }
            $timestamp = $parsed;
        }

        return (new DateTimeImmutable('@' . (int) $timestamp))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeImmutable::ATOM);
    }

    /**
     * Build a plain-text summary for the page-list preview panel.
     *
     * The preview wants readable text, not markup: rendered images and
     * shortcodes break more than they help in a small side panel, and
     * truncating the raw markdown leaves orphaned link/image fragments
     * (admin2#110). So we render the content, strip the tags, and truncate
     * the resulting text.
     *
     * Page::summary(size, textOnly: true) is tried first so an author's manual
     * "===" summary divider and summary config are honored. It renders through
     * Twig, which can throw in the headless API request context (no active
     * page / Twig env); on failure we render the raw markdown on its own — no
     * Twig — and strip that instead.
     */
    private function buildTextSummary(PageInterface $resource, ?int $summarySize): string
    {
        $max = ($summarySize !== null && $summarySize > 0) ? $summarySize : 300;

        try {
            $text = (string) $resource->summary($max, true);
        } catch (\Throwable) {
            $text = strip_tags($this->renderMarkdown($resource));
        }

        // Drop any Twig / shortcode tokens the fallback path left unprocessed.
        $text = preg_replace('/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}/s', '', $text) ?? $text;
        $text = preg_replace('/\[\/?[a-zA-Z][^\]]*\]/', '', $text) ?? $text;

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return Utils::truncate($text, $max, true, ' ', '…');
    }

    /**
     * Render a page's raw markdown to HTML without the Twig pass (which needs
     * an active request / Twig env and throws headless). Mirrors Grav's own
     * markdown step: Parsedown driven by an Excerpts instance, so the caller
     * can strip clean tags rather than partially-processed markdown.
     */
    private function renderMarkdown(PageInterface $resource): string
    {
        $config = Grav::instance()['config'];

        $markdownDefaults = (array) $config->get('system.pages.markdown');
        $header = $resource->header();
        if (isset($header->markdown) && is_array($header->markdown)) {
            $markdownDefaults = array_merge($markdownDefaults, $header->markdown);
        }

        $excerpts = new Excerpts($resource, [
            'markdown' => $markdownDefaults,
            'images' => $config->get('system.images', []),
        ]);

        $parsedown = !empty($markdownDefaults['extra'])
            ? new ParsedownExtra($excerpts)
            : new Parsedown($excerpts);

        return (string) $parsedown->text((string) $resource->rawMarkdown());
    }
}
