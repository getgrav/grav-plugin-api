<?php

declare(strict_types=1);

// Plugin's own autoloader (includes PHPUnit, firebase/php-jwt, fast-route)
require_once __DIR__ . '/../vendor/autoload.php';

// Attempt to load Grav core autoloader when running within a Grav installation.
// This enables tests that depend on real Grav framework classes to work.
$gravAutoloader = __DIR__ . '/../../../../vendor/autoload.php';
if (file_exists($gravAutoloader)) {
    require_once $gravAutoloader;
}

// If the Grav core classes are still not available (e.g. running standalone),
// load our minimal stub implementations so the plugin classes can be
// instantiated and unit-tested without the full Grav framework.
if (!class_exists(\Grav\Common\Grav::class, false)) {
    require_once __DIR__ . '/Stubs/GravStubs.php';
}

date_default_timezone_set('UTC');
