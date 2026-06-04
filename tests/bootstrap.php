<?php

declare(strict_types=1);

// Plugin's own autoloader (includes PHPUnit, firebase/php-jwt, fast-route)
require_once __DIR__ . '/../vendor/autoload.php';

// Load Grav core's autoloader so tests can reach the framework — and the
// symfony/yaml that the plugin no longer bundles (it relies on Grav's copy; see
// the "replace" entry in composer.json). The relative path resolves when the
// plugin runs as user/plugins/api inside a Grav install. Because a development
// clone is usually symlinked in, __DIR__ resolves to the source checkout and
// that relative path misses — so we also walk up from the shell's working
// directory (which preserves the symlinked path) to find the hosting Grav. As
// a last resort set GRAV_ROOT explicitly, e.g. `GRAV_ROOT=/path/to/grav composer test`.
$findGravAutoloader = static function (): ?string {
    $gravRoot = getenv('GRAV_ROOT');

    // Explicit override and the in-install relative path come first.
    $direct = [
        $gravRoot ? rtrim($gravRoot, '/') . '/vendor/autoload.php' : null,
        __DIR__ . '/../../../../vendor/autoload.php',
    ];
    foreach ($direct as $candidate) {
        if ($candidate && is_file($candidate)) {
            return $candidate;
        }
    }

    // Walk up from the symlink-preserving shell CWD and the resolved CWD,
    // looking for a directory that holds both a Composer autoloader and the
    // Grav core marker (so we don't grab the plugin's own vendor).
    $starts = array_filter([getenv('PWD') ?: null, getcwd() ?: null]);
    foreach ($starts as $dir) {
        $dir = rtrim($dir, '/');
        while ($dir !== '' && $dir !== '/' && $dir !== '.') {
            if (is_file($dir . '/vendor/autoload.php') && is_file($dir . '/system/defines.php')) {
                return $dir . '/vendor/autoload.php';
            }
            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }

    return null;
};

$gravAutoloader = $findGravAutoloader();
if ($gravAutoloader !== null) {
    require_once $gravAutoloader;
}

// If Grav core (and thus symfony/yaml) is still unavailable — e.g. running fully
// standalone without GRAV_ROOT — load our minimal stub implementations so the
// plugin classes can still be instantiated and unit-tested.
if (!class_exists(\Grav\Common\Grav::class, false)) {
    require_once __DIR__ . '/Stubs/GravStubs.php';
}

date_default_timezone_set('UTC');
