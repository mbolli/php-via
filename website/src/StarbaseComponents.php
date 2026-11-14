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
        'sb-copy-button' => 'copy-button@2a9e7e7646d2/copy-button.min.js',
        'sb-odometer' => 'odometer@51fcd47a02c6/odometer.min.js',
        'sb-qr-code' => 'qr-code@0d8b2096aa94/qr-code.min.js',
    ];

    /** @var array<string, string> every file the modules load, relative to BASE_URL => its hash in Starbase's catalog */
    public const array INTEGRITY = [
        'copy-button@2a9e7e7646d2/copy-button.min.js' => 'sha384-aMkZmUDhQjFqYLWP9bVqgSU08IrkWqNopw1Svh6yC18xbgsJbTAV9ndgjpW/gj7Q',
        'odometer@51fcd47a02c6/odometer.min.js' => 'sha384-ON7wS6L5jW+lSU5TEOmFR0X/l+MunP/mNPyssll3wm9JSgCtqOZoWPkQlJohcvM3',
        'qr-code@0d8b2096aa94/qr-code.min.js' => 'sha384-lbjsew2dJTUODW2J/zRCPydPl1j6LcSeJ0H0fH4JlaxZr8Xmf/tIoHEtmuaXikbz',
        'qr-code@0d8b2096aa94/vendor/uqr.min.mjs' => 'sha384-lg8RyFvkbOp4oa6Ny6ZQLHos0CvIWFIdAQyc0NFGwKwdqeQIJJuF1Xh6tAlkACb3',
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
