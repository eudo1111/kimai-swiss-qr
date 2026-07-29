<?php

/**
 * Scoped autoloader for SwissQrBundle dependencies.
 *
 * Do NOT load the full Composer autoload.php from vendor/: it registers a second
 * copy of Symfony packages and fatally redeclares traits/classes in Kimai prod
 * (debug=false) when the compiled container include_once's those paths.
 *
 * Kimai already provides Symfony; we only register library-specific namespaces.
 */

declare(strict_types=1);

if (\defined('KIMAI_SWISS_QR_AUTOLOAD')) {
    return;
}

\define('KIMAI_SWISS_QR_AUTOLOAD', true);

$vendorDir = \dirname(__DIR__) . '/vendor';
$classLoader = $vendorDir . '/composer/ClassLoader.php';
$psr4File = $vendorDir . '/composer/autoload_psr4.php';

if (!\is_file($classLoader) || !\is_file($psr4File)) {
    throw new \RuntimeException('SwissQrBundle: run "composer install" inside the plugin directory.');
}

if (!\class_exists(\Composer\Autoload\ClassLoader::class, false)) {
    require_once $classLoader;
}

$loader = new \Composer\Autoload\ClassLoader();
$psr4 = require $psr4File;

foreach ($psr4 as $namespace => $paths) {
    // Kimai's autoloader already covers the plugin and all Symfony packages.
    if (\str_starts_with($namespace, 'Symfony\\') || \str_starts_with($namespace, 'KimaiPlugin\\SwissQrBundle\\')) {
        continue;
    }
    $loader->setPsr4($namespace, $paths);
}

$classMapFile = $vendorDir . '/composer/autoload_classmap.php';
if (\is_file($classMapFile)) {
    $classMap = require $classMapFile;
    $filtered = [];
    foreach ($classMap as $class => $file) {
        if (\str_starts_with($class, 'Symfony\\') || \str_contains((string) $file, '/symfony/')) {
            continue;
        }
        // Skip global polyfill stubs (Collator, Locale, …) — Kimai/intl provide these.
        if (!\str_contains($class, '\\')) {
            continue;
        }
        $filtered[$class] = $file;
    }
    if ($filtered !== []) {
        $loader->addClassMap($filtered);
    }
}

// Append after Kimai so host Symfony always wins on any overlap.
$loader->register(false);

$filesFile = $vendorDir . '/composer/autoload_files.php';
if (\is_file($filesFile)) {
    $files = require $filesFile;
    foreach ($files as $file) {
        if (\str_contains((string) $file, '/symfony/')) {
            continue;
        }
        require_once $file;
    }
}
