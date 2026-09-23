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
use Grav\Plugin\Api\Services\FrontmatterReader;

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
        // "true" even when the frontmatter explicitly says false. Read the
        // frontmatter from the .md file instead, so published/visible and
        // everything else in the header are accurate regardless of which
        // controller path we came through. FrontmatterReader parses each file
        // once and remembers it until the file changes.
        if (empty($headerArr) && $resource instanceof \Grav\Framework\Flex\Pages\FlexPageObject) {
            // Prefer the page's own language, then the active language, then
            // the untyped default, then any matching {template}*.md.
            $lang = \Grav\Common\Grav::instance()['language'] ?? null;
            $active = $lang && method_exists($lang, 'getLanguage') ? $lang->getLanguage() : null;
            $headerArr = FrontmatterReader::forPage($resource, is_string($active) && $active !== '' ? $active : null);
        }

        // For flex-indexed PageObject listings the in-memory header is empty
        // (we re-parsed the .md file into $headerArr above). $resource->title()
        // and ->menu() in that mode fall back to a slug-derived label
        // ("Contact-us") even when the frontmatter has a real title.
        // Prefer the parsed-header value so the listing reads the same as
        // the detail endpoint.
        $headerTitle = $headerArr['title'] ?? null;
        $headerMenu = $headerArr['menu'] ?? null;

        $published = array_key_exists('published', $headerArr)
            ? (bool) $headerArr['published']
            : $resource->published();

        $data = [
            'route' => $resource->route(),
            // Structural route — for the home page, route() returns the
            // public alias '/' but rawRoute() returns the actual page like
            // '/home'. Clients editing/finding pages should prefer this.
            'raw_route' => $resource->rawRoute(),
            // Structural route of the parent, from the real hierarchy — '/'
            // for a genuine top-level page (parent is the pages-root). Clients
            // must use THIS (not a string-split of the public route) when
            // moving/reparenting: under home.hide_in_urls a home child's public
            // route has the home segment stripped, so guessing the parent from
            // it yields '/' and relocates the page to the site root
            // (getgrav/grav-plugin-admin2#132).
            'parent_route' => (static function () use ($resource): string {
                $parent = $resource->parent();
                return ($parent && !$parent->root()) ? $parent->rawRoute() : '/';
            })(),
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
            'published' => $published,
            // `published` above is a single boolean and can't tell a draft
            // apart from a page that is merely scheduled or already expired:
            // on the legacy path Page::setPublishState() folds the dates into
            // it (a scheduled page reads false, same as a draft), while on the
            // flex path published() is a bare header read that ignores the
            // dates entirely (a scheduled page reads true and looks live).
            // These three fields are purely additive — `published` keeps
            // exactly the value it has always had (admin2#2523).
            ...$this->publishWindow($headerArr, $published),
            'visible' => array_key_exists('visible', $headerArr) ? (bool)$headerArr['visible'] : $resource->visible(),
            'routable' => $resource->routable(),
            'date' => $this->formatTimestamp($resource->date()),
            'modified' => $this->formatTimestamp($resource->modified()),
            'order' => $resource->order(),
            'has_children' => count($resource->children()) > 0,
        ];

        // Page lists with `fields=summary` leave the full frontmatter out. It is
        // still read above, because published, visible, title and menu come
        // from it.
        if (!($options['include_header'] ?? true)) {
            unset($data['header']);
        }

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
     * Resolve the page's publishing window into three additive fields:
     * `publish_date`, `unpublish_date` (both ISO 8601 or null) and
     * `publish_state` (published|unpublished|scheduled|expired).
     *
     * The dates are read from the header array rather than from
     * PageInterface::publishDate()/unpublishDate() on purpose: the header
     * array is the one source that is correct on BOTH paths. During a
     * flex-indexed listing the object's own header is empty, so the flex
     * accessors return null for a page that plainly has a `publish_date:` in
     * its frontmatter — the same materialization gap that forces the
     * re-parse above for `published`/`visible`.
     *
     * @param array<string,mixed> $headerArr
     * @return array{publish_date: ?string, unpublish_date: ?string, publish_state: string}
     */
    private function publishWindow(array $headerArr, bool $published): array
    {
        $dateformat = $headerArr['dateformat'] ?? null;
        $dateformat = is_string($dateformat) && $dateformat !== '' ? $dateformat : null;

        $publishTs = $this->headerDate($headerArr['publish_date'] ?? null, $dateformat);
        $unpublishTs = $this->headerDate($headerArr['unpublish_date'] ?? null, $dateformat);

        return [
            'publish_date' => $this->formatTimestamp($publishTs),
            'unpublish_date' => $this->formatTimestamp($unpublishTs),
            'publish_state' => $this->resolvePublishState($headerArr, $published, $publishTs, $unpublishTs),
        ];
    }

    /**
     * Parse a frontmatter date into a Unix timestamp using Grav's own parser,
     * honoring the page's `dateformat:`.
     *
     * Utils::date2timestamp() is mandatory here: `10/02/2026` is day-first or
     * month-first depending entirely on `dateformat`, and strtotime() (or a
     * client-side `new Date()`) always guesses month-first and silently reads
     * the wrong day (getgrav/grav-plugin-admin2#134).
     */
    private function headerDate(mixed $value, ?string $dateformat): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        // A DateTime survives Grav's YAML parse; anything else non-scalar
        // (an array left by the json round-trip, say) is not a date.
        if (!$value instanceof \DateTimeInterface && !is_scalar($value)) {
            return null;
        }

        $timestamp = Utils::date2timestamp($value, $dateformat);

        return is_int($timestamp) && $timestamp !== 0 ? $timestamp : null;
    }

    /**
     * Decide which of the four publish states a page is in.
     *
     * Mirrors core's precedence in Page::setPublishState(): the dates are only
     * consulted when `system.pages.publish_dates` is on AND the frontmatter
     * carries no explicit `published:` key — an explicit value wins outright
     * and makes both dates inert. Core uses isset(), so a `published:` with a
     * null value counts as absent; array_key_exists() would not match that.
     *
     * @param array<string,mixed> $headerArr
     */
    private function resolvePublishState(array $headerArr, bool $published, ?int $publishTs, ?int $unpublishTs): string
    {
        if (isset($headerArr['published'])) {
            return $published ? 'published' : 'unpublished';
        }

        if ($this->publishDatesEnabled()) {
            $now = time();

            // Core evaluates the unpublish date first and the publish date
            // second, so on contradictory dates the publish date has the final
            // say. Keep that order of precedence here.
            if ($publishTs !== null && $publishTs > $now) {
                return 'scheduled';
            }

            if ($unpublishTs !== null && $unpublishTs < $now) {
                return 'expired';
            }
        }

        return $published ? 'published' : 'unpublished';
    }

    /**
     * Whether core would act on publish/unpublish dates at all.
     */
    private function publishDatesEnabled(): bool
    {
        try {
            return (bool) Grav::instance()['config']->get('system.pages.publish_dates', true);
        } catch (\Throwable) {
            return true;
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
            // Page::summary(size, textOnly: true) is documented to return text, but
            // core short-circuits to full rendered HTML when summaries are disabled
            // (site.summary.enabled: false), ignoring textOnly. Strip tags here so
            // the `summary` field is always plain text as clients expect — otherwise
            // the admin renders raw markup as literal text (admin2#125).
            $text = strip_tags((string) $resource->summary($max, true));
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
