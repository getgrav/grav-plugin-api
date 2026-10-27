<?php

declare(strict_types=1);

namespace Grav\Plugin\Api\Services;

/**
 * Directory containment for already-resolved paths.
 *
 * realpath() hands back '\' separators on Windows while paths built in PHP or
 * returned by the locator use '/', so a check like
 * `str_starts_with($real, $realBase . '/')` never matches there and every
 * operation behind it is refused (#66). On Windows both sides are compared
 * with '/' separators. The base always ends on a separator, so a sibling whose
 * name merely extends it (`media2`, `media-private`) never counts as inside.
 */
final class PathContainment
{
    /**
     * Whether $path sits inside $base. Pass realpath() results for both: this
     * only compares strings, it does not resolve `..` or symlinks.
     *
     * @param bool   $allowSelf Whether $path equal to $base counts as inside.
     * @param string $separator The platform separator. Only '\' is rewritten to
     *                          '/': on Unix a backslash is an ordinary filename
     *                          character, and folding it would let a sibling
     *                          folder named `media\x` pass as `media/x`.
     */
    public static function isWithin(string $path, string $base, bool $allowSelf = false, string $separator = DIRECTORY_SEPARATOR): bool
    {
        if ($path === '' || $base === '') {
            return false;
        }

        if ($separator === '\\') {
            $path = str_replace('\\', '/', $path);
            $base = str_replace('\\', '/', $base);
        }
        $path = rtrim($path, '/');
        $base = rtrim($base, '/');

        if ($path === $base) {
            return $allowSelf;
        }

        return str_starts_with($path, $base . '/');
    }
}
