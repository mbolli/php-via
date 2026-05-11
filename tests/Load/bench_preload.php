<?php

declare(strict_types=1);

/**
 * bench_preload.php — OPcache preload script for the bench suite.
 *
 * Preloads every PHP file under src/ into the OPcache shared memory segment
 * at server startup, eliminating compilation AND class-linking latency for
 * the first requests.
 *
 * Rules for preload scripts (PHP 7.4+):
 *   - The autoloader MUST be required first so that vendor interfaces/parents
 *     are resolved when PHP links the preloaded classes. Without this, classes
 *     that implement PSR interfaces end up "unlinked" and the worker crashes
 *     with SIGSEGV on the first real request.
 *   - Must not instantiate user-defined classes.
 *   - Run in the master process before workers fork; the compiled + linked
 *     bytecode is shared with all forked workers via OPcache shared memory.
 *
 * Activated via php.ini / CLI flag:
 *   opcache.preload=/path/to/bench_preload.php
 *   opcache.preload_user=www-data   ; required unless running as root
 *
 * Referenced by the opcache-preload profile in bench_opcache.php.
 */

if (!function_exists('opcache_compile_file')) {
    fwrite(STDERR, "bench_preload.php: opcache_compile_file() is not available — OPcache not loaded or disabled\n");

    return;
}

$vendorAutoload = realpath(__DIR__ . '/../../vendor/autoload.php');
$srcDir         = realpath(__DIR__ . '/../../src');

if ($vendorAutoload === false) {
    fwrite(STDERR, "bench_preload.php: cannot resolve vendor/autoload.php — run composer install first\n");

    return;
}

if ($srcDir === false) {
    fwrite(STDERR, "bench_preload.php: cannot resolve src/ — check the path relative to this script\n");

    return;
}

// Load vendor autoloader so PSR interfaces and all dependency classes are
// already in memory when PHP tries to link our preloaded src/ classes.
// Without this, classes implementing PSR\Http\Server\MiddlewareInterface etc.
// are "unlinked" and cause SIGSEGV in the first worker request.
require_once $vendorAutoload;

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS)
);

$compiled = 0;

foreach ($iter as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    // require_once both compiles into OPcache bytecode AND links the class
    // (resolving interfaces/parents against already-loaded vendor classes).
    // This is the correct approach when dependencies live outside the preload set.
    require_once $file->getPathname();
    ++$compiled;
}
