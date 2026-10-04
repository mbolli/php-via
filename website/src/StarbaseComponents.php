<?php

declare(strict_types=1);

namespace PhpVia\Website;

use Mbolli\PhpVia\Config;

/**
 * The Starbase components the site copies into public/vendor/starbase (see its README). They go into
 * php-via's import map with their integrity hashes, and a page that uses one imports it by its tag,
 * e.g. `<script type="module">import 'sb-qr-code'</script>`.
 */
final class StarbaseComponents {
    public const string BASE_URL = '/vendor/starbase/';

    /** @var array<string, string> tag => module, relative to BASE_URL */
    public const array MODULES = [
        'sb-copy-button' => 'copy-button@9d292216a6f8/copy-button.min.js',
        'sb-odometer' => 'odometer@a3e5a90d0ca0/odometer.min.js',
        'sb-qr-code' => 'qr-code@ecc5a99c314a/qr-code.min.js',
    ];

    /** @var array<string, string> every file the modules load, relative to BASE_URL => its hash in Starbase's catalog */
    public const array INTEGRITY = [
        'copy-button@9d292216a6f8/copy-button.min.js' => 'sha384-aMkZmUDhQjFqYLWP9bVqgSU08IrkWqNopw1Svh6yC18xbgsJbTAV9ndgjpW/gj7Q',
        'odometer@a3e5a90d0ca0/odometer.min.js' => 'sha384-RBm01x1ojV5PgaOVyhKU9P2L54HRDDa1MK2EEmVGp4fdT/2PYvq6X7NS5j89zU8Q',
        'qr-code@ecc5a99c314a/qr-code.min.js' => 'sha384-xSwmJUzaHfLDWJKbZJhr17n5cxupQxa0YcTvRo9uzTJGoA0uamggorZRgVx84LmG',
        'qr-code@ecc5a99c314a/vendor/uqr.min.mjs' => 'sha384-lg8RyFvkbOp4oa6Ny6ZQLHos0CvIWFIdAQyc0NFGwKwdqeQIJJuF1Xh6tAlkACb3',
    ];

    public static function register(Config $config): Config {
        $imports = [];
        foreach (self::MODULES as $tag => $file) {
            $imports[$tag] = self::BASE_URL . $file;
        }

        $integrity = [];
        foreach (self::INTEGRITY as $file => $hash) {
            $integrity[self::BASE_URL . $file] = $hash;
        }

        return $config->withImportMap($imports, $integrity);
    }

    /**
     * For Config::withStaticCacheControl(): a year for the files in a versioned Starbase folder, whose
     * URL changes with every new version, and php-via's default (null) for every other file.
     */
    public static function cacheControl(string $filePath): ?string {
        $dir = realpath(\dirname(__DIR__) . '/public' . self::BASE_URL);

        return $dir !== false && preg_match('#^' . preg_quote($dir, '#') . '/[a-z0-9-]+@[0-9a-f]{12}/#', $filePath) === 1
            ? 'public, max-age=31536000, immutable'
            : null;
    }
}
