<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

use Grav\Common\Grav;

/**
 * The admin-next web component modules a plugin (or, for fields, a theme)
 * ships under `admin-next/`, and how they are served as same-origin ES
 * modules.
 *
 * Admin2 used to fetch each script with its JWT, prepend the line that tells
 * the module its element tag, and import the result from a `blob:` URL. A
 * strict Content-Security-Policy can't allow `blob:` scripts without opening
 * the door to any script the page can build, so the API now does the
 * prepending and Admin2 imports the module straight from
 * `/admin-next/modules/...`. These files are already served publicly by the
 * webserver from `user/plugins/<slug>/admin-next/`, so the route needs no
 * authentication.
 *
 * `v` is the file's mtime and size (the same values as its ETag). The registry
 * payloads Admin2 already reads (custom fields, floating widgets, context
 * panels, dashboard widgets, reports, plugin pages) carry it, so a module URL
 * changes whenever its file does and can be cached for a year.
 */
final class AdminModules
{
    /**
     * Module type => where it lives under `admin-next/`, the global the
     * prepended line sets, and whether the URL names the file (modal, report
     * and field) or the plugin slug does (page, widget, panel).
     *
     * @var array<string, array{dir: string, global: string, named: bool}>
     */
    public const TYPES = [
        'page' => ['dir' => 'pages', 'global' => '__GRAV_PAGE_TAG', 'named' => false],
        'widget' => ['dir' => 'widgets', 'global' => '__GRAV_WIDGET_TAG', 'named' => false],
        'panel' => ['dir' => 'panels', 'global' => '__GRAV_PANEL_TAG', 'named' => false],
        'modal' => ['dir' => 'modals', 'global' => '__GRAV_MODAL_TAG', 'named' => true],
        'report' => ['dir' => 'reports', 'global' => '__GRAV_REPORT_TAG', 'named' => true],
        'field' => ['dir' => 'fields', 'global' => '__GRAV_FIELD_TAG', 'named' => true],
    ];

    /**
     * A custom element name, ASCII only: starts with a lowercase letter and
     * contains a hyphen. Every tag Admin2 builds (`grav-<slug>--page`,
     * `grav-widget-<id>`, ...) matches; anything with quotes, spaces or markup
     * can't.
     */
    public const TAG_PATTERN = '/^[a-z][a-z0-9._]*-[a-z0-9._-]*$/';

    /** A file name under `admin-next/<dir>/`, without the `.js`. */
    public const NAME_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/';

    public static function isValidTag(string $tag): bool
    {
        return strlen($tag) <= 128 && preg_match(self::TAG_PATTERN, $tag) === 1;
    }

    public static function isValidName(string $name): bool
    {
        return strlen($name) <= 128 && !str_contains($name, '..') && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * The module file for a type inside a package folder. `$name` is the file
     * name for named types; the others use the slug.
     */
    public static function file(string $packagePath, string $type, string $slug, ?string $name = null): string
    {
        $def = self::TYPES[$type];
        $base = $def['named'] ? (string) $name : $slug;

        return rtrim($packagePath, '/') . '/admin-next/' . $def['dir'] . '/' . basename($base) . '.js';
    }

    /** mtime-size of a file in hex, as in its ETag, or null when it is missing. */
    public static function version(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        return sprintf('%x-%x', @filemtime($file) ?: 0, @filesize($file) ?: 0);
    }

    /** The line put in front of the module so it knows its element tag. */
    public static function prelude(string $type, string $tag): string
    {
        $global = self::TYPES[$type]['global'];

        return 'window.' . $global . ' = ' . json_encode($tag, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ";\n";
    }

    /**
     * The version of a package's module, for a registry payload. Null when the
     * package or the file isn't there, or the slug or name isn't valid.
     *
     * @param 'plugins'|'themes' $kind
     */
    public static function versionFor(Grav $grav, string $type, string $slug, ?string $name = null, string $kind = 'plugins'): ?string
    {
        if (!isset(self::TYPES[$type]) || !self::isValidName($slug)) {
            return null;
        }
        if (self::TYPES[$type]['named'] && ($name === null || !self::isValidName($name))) {
            return null;
        }

        try {
            $path = self::packagePath($grav, $slug, $kind);
        } catch (\Throwable) {
            // No locator (or a broken stream): leave the payload unversioned.
            return null;
        }

        return $path === null ? null : self::version(self::file($path, $type, $slug, $name));
    }

    /**
     * @param 'plugins'|'themes' $kind
     */
    private static function packagePath(Grav $grav, string $slug, string $kind): ?string
    {
        $base = $kind === 'themes' ? 'themes' : 'plugins';
        $locator = $grav['locator'];
        $path = $locator->findResource("{$base}://{$slug}", true);
        if (!$path || !is_dir($path)) {
            $path = $locator->findResource("user://{$base}/{$slug}", true);
        }

        return $path && is_dir($path) ? $path : null;
    }
}
