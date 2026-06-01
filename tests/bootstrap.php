<?php

declare(strict_types=1);

// Plugin's own autoloader (includes PHPUnit, firebase/php-jwt, fast-route)
require_once __DIR__ . '/../vendor/autoload.php';

// Load Grav core's autoloader so tests can reach the framework — and the
// symfony/yaml that the plugin no longer bundles (it relies on Grav's copy; see
// the "replace" entry in composer.json). The relative path resolves when the
// plugin runs as user/plugins/api inside a Grav install; from a standalone
// source clone (e.g. symlinked in for development) set GRAV_ROOT to the Grav
// root, e.g. `GRAV_ROOT=/path/to/grav composer test`.
$gravRoot = getenv('GRAV_ROOT');
$candidates = [
    $gravRoot ? rtrim($gravRoot, '/') . '/vendor/autoload.php' : null,
    __DIR__ . '/../../../../vendor/autoload.php',
];
foreach ($candidates as $gravAutoloader) {
    if ($gravAutoloader && is_file($gravAutoloader)) {
        require_once $gravAutoloader;
        break;
    }
}

// If Grav core (and thus symfony/yaml) is still unavailable — e.g. running fully
// standalone without GRAV_ROOT — load our minimal stub implementations so the
// plugin classes can still be instantiated and unit-tested.
if (!class_exists(\Grav\Common\Grav::class, false)) {
    require_once __DIR__ . '/Stubs/GravStubs.php';
}

date_default_timezone_set('UTC');
