<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * The Datastar bundles php-via serves at /datastar.js (provenance in public/DATASTAR.md).
 *
 * @internal
 */
final class DatastarBundle {
    /**
     * Absolute path of the bundle: Starbase's Datastar + Rocket build or the plain Datastar one.
     */
    public static function path(bool $rocket): string {
        return \dirname(__DIR__, 2) . '/public/' . ($rocket ? 'datastar-rocket.js' : 'datastar.js');
    }

    /**
     * First 10 hex digits of the file's SHA-256, or null when it cannot be read.
     */
    public static function version(string $path): ?string {
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        return $hash === false ? null : substr($hash, 0, 10);
    }

    /**
     * '<basePath>datastar.js?v=<version>', or without the query when there is no version.
     */
    public static function url(string $basePath, ?string $version): string {
        return $basePath . 'datastar.js' . ($version === null ? '' : '?v=' . $version);
    }
}
