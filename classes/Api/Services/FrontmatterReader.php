<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads a page's frontmatter straight from its .md file, remembering each
 * parsed file by its path, mtime, size and inode.
 *
 * Flex-indexed page objects report an EMPTY header during listings (the index
 * only holds summary fields), so the page serializer and the page ACL both fall
 * back to reading the file. On a 500-row list that was 500 reads and YAML
 * parses, and a second round for non-super users when the ACL asked again, plus
 * the parents it walks up to. Each file is now parsed once per request, and
 * once overall until it changes: the Grav cache keeps the parsed header with
 * the file's mtime, size and inode, so an edited file is simply parsed again.
 * The inode matters because Grav normally saves through a temp file and a
 * rename, so even an edit that keeps the size and lands in the same second
 * reads as a different file.
 */
final class FrontmatterReader
{
    private const CACHE_PREFIX = 'api-frontmatter-v1-';

    /** @var array<string, array{0: string, 1: array<string, mixed>}> path => [file signature, header] */
    private static array $memo = [];

    /**
     * The frontmatter of a page's content file, picked the way Grav picks it.
     *
     * On a multi-language site that is the first file that exists in the
     * language order: the page's own language, then the viewed language
     * (`$activeLanguage`, else Grav's active or default one) and its fallback
     * chain, ending with the untyped `{template}.md`. A page shown in one
     * language never gets another language's header just because its own file
     * has none. Without language support the untyped file is read, else any
     * `{template}*.md` with frontmatter (a single-language site whose files
     * still carry a language suffix).
     *
     * @return array<string, mixed>
     */
    public static function forPage(PageInterface $page, ?string $activeLanguage = null): array
    {
        $path = $page->path();
        // The content file's own name: a module's template() is
        // `modular/<name>`, and a `template` header names no file at all.
        $template = PageTemplate::fileType($page);
        if (!$path || !$template) {
            return [];
        }

        $languages = self::languageOrder($page->language() ?: null, $activeLanguage ?: null);
        if ($languages !== null) {
            foreach ($languages as $code) {
                $file = $path . '/' . $template . ($code !== '' ? '.' . $code : '') . '.md';
                if (is_file($file)) {
                    return self::parse($file);
                }
            }

            return [];
        }

        $header = self::parse($path . '/' . $template . '.md');
        if ($header !== []) {
            return $header;
        }

        foreach (glob($path . '/' . $template . '*.md') ?: [] as $file) {
            $header = self::parse($file);
            if ($header !== []) {
                return $header;
            }
        }

        return [];
    }

    /**
     * The YAML frontmatter of one file: the block between a leading `---` line
     * and the next one. Empty for a missing file, a file without frontmatter or
     * frontmatter that doesn't parse to a map.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $file): array
    {
        $stat = is_file($file) ? @stat($file) : false;
        if ($stat === false) {
            return [];
        }
        $signature = $stat['mtime'] . ':' . $stat['size'] . ':' . $stat['ino'];

        $memo = self::$memo[$file] ?? null;
        if ($memo !== null && $memo[0] === $signature) {
            return $memo[1];
        }

        $cache = self::cache();
        $key = self::CACHE_PREFIX . md5($file);
        if ($cache !== null) {
            $cached = $cache->fetch($key);
            if (is_array($cached) && ($cached[0] ?? null) === $signature && is_array($cached[1] ?? null)) {
                self::$memo[$file] = $cached;

                return $cached[1];
            }
        }

        $header = self::parseContents(@file_get_contents($file));
        $entry = [$signature, $header];
        self::$memo[$file] = $entry;
        $cache?->save($key, $entry);

        return $header;
    }

    /**
     * Forget what this request has read. For tests; the Grav cache entries
     * stay, and are checked against the file anyway.
     */
    public static function reset(): void
    {
        self::$memo = [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseContents(string|false $contents): array
    {
        if ($contents === false) {
            return [];
        }
        // Grav frontmatter: the block between a leading `---` line and the next
        // one. Like core, the closing `---` may end the file (a header-only
        // page saved without a final newline) and the block may be empty.
        if (!preg_match('/^---[ \t]*\r?\n(?:(.*?)\r?\n)?---[ \t]*(?:\r?\n|$)/s', ltrim($contents), $matches)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($matches[1] ?? '');
        } catch (Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Content-file languages to try, in order ('' is the untyped file), or
     * null when neither the site nor the caller knows a language.
     *
     * @return list<string>|null
     */
    private static function languageOrder(?string $pageLanguage, ?string $activeLanguage): ?array
    {
        $order = $pageLanguage !== null ? [$pageLanguage] : [];
        $viewed = $activeLanguage ?? $pageLanguage;

        $service = self::languageService();
        if ($service !== null && $service->enabled()) {
            $viewed ??= $service->getActive() ?: $service->getDefault() ?: null;
            foreach ($service->getFallbackLanguages($viewed, true) as $code) {
                $order[] = (string) $code;
            }
        } elseif ($viewed !== null) {
            $order[] = $viewed;
            $order[] = '';
        } else {
            return null;
        }

        return array_values(array_unique($order));
    }

    private static function languageService(): ?object
    {
        try {
            $language = Grav::instance()['language'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_object($language) && method_exists($language, 'enabled') && method_exists($language, 'getFallbackLanguages')
            ? $language
            : null;
    }

    private static function cache(): ?object
    {
        try {
            $cache = Grav::instance()['cache'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_object($cache) && method_exists($cache, 'fetch') && method_exists($cache, 'save') ? $cache : null;
    }
}
