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
     * The frontmatter of the first of a page's content files that has one:
     * the file for the page's own language, then (when given) the active
     * language's, then the untyped `{template}.md`, then any other
     * `{template}*.md`. Empty when none of them has frontmatter.
     *
     * @return array<string, mixed>
     */
    public static function forPage(PageInterface $page, ?string $activeLanguage = null): array
    {
        $path = $page->path();
        $template = $page->template();
        if (!$path || !$template) {
            return [];
        }

        $candidates = [];
        $language = $page->language();
        if ($language) {
            $candidates[] = $path . '/' . $template . '.' . $language . '.md';
        }
        if ($activeLanguage) {
            $candidates[] = $path . '/' . $template . '.' . $activeLanguage . '.md';
        }
        $candidates[] = $path . '/' . $template . '.md';

        foreach ($candidates as $file) {
            $header = self::parse($file);
            if ($header !== []) {
                return $header;
            }
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
        // Grav frontmatter: content between leading `---\n` and the next `---\n`.
        if (!preg_match('/^---\r?\n(.*?)\r?\n---\r?\n/s', $contents, $matches)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($matches[1]);
        } catch (Throwable) {
            return [];
        }

        return is_array($parsed) ? $parsed : [];
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
