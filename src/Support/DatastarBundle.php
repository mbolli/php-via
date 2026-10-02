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
     * The file's version, the first 10 hex digits of its SHA-256, and its Subresource Integrity value
     * (sha384), both null when the file cannot be read.
     *
     * @return array{version: ?string, integrity: ?string}
     */
    public static function fingerprint(string $path): array {
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            return ['version' => null, 'integrity' => null];
        }

        return [
            'version' => substr(hash('sha256', $bytes), 0, 10),
            'integrity' => 'sha384-' . base64_encode(hash('sha384', $bytes, true)),
        ];
    }

    /**
     * '<basePath>datastar.js?v=<version>', or without the query when there is no version.
     */
    public static function url(string $basePath, ?string $version): string {
        return $basePath . 'datastar.js' . ($version === null ? '' : '?v=' . $version);
    }
}
