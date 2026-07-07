<?php

declare(strict_types=1);

use Mbolli\PhpVia\Support\ConditionalGet;

/*
 * Pure ETag / If-None-Match / If-Modified-Since logic backing static-file
 * conditional GET (datastar.js, via.css, withStaticDir() files). Kept OpenSwoole-free
 * so the decision logic is fully covered without booting a server.
 */

describe('ConditionalGet::etag()', function (): void {
    test('is a weak validator derived from mtime + size', function (): void {
        expect(ConditionalGet::etag(1_700_000_000, 1234))->toBe('W/"' . dechex(1_700_000_000) . '-' . dechex(1234) . '"');
    });

    test('differs when mtime differs', function (): void {
        $a = ConditionalGet::etag(1000, 500);
        $b = ConditionalGet::etag(1001, 500);
        expect($a)->not->toBe($b);
    });

    test('differs when size differs', function (): void {
        $a = ConditionalGet::etag(1000, 500);
        $b = ConditionalGet::etag(1000, 501);
        expect($a)->not->toBe($b);
    });
});

describe('ConditionalGet::lastModified()', function (): void {
    test('formats as an HTTP-date in GMT', function (): void {
        // 2024-01-15 12:00:00 UTC
        $mtime = gmmktime(12, 0, 0, 1, 15, 2024);
        expect(ConditionalGet::lastModified($mtime))->toBe('Mon, 15 Jan 2024 12:00:00 GMT');
    });
});

describe('ConditionalGet::isNotModified() — If-None-Match', function (): void {
    test('matches an identical ETag', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        expect(ConditionalGet::isNotModified($etag, null, $etag, 1000))->toBeTrue();
    });

    test('does not match a different ETag', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        $other = ConditionalGet::etag(1000, 999);
        expect(ConditionalGet::isNotModified($other, null, $etag, 1000))->toBeFalse();
    });

    test('matches weakly regardless of W/ prefix on either side', function (): void {
        $etag = ConditionalGet::etag(1000, 500); // already W/"..."
        $bare = ltrim($etag, 'W/');
        expect(ConditionalGet::isNotModified($bare, null, $etag, 1000))->toBeTrue();
    });

    test('matches any one of a comma-separated list', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        $header = 'W/"deadbeef", ' . $etag . ', W/"cafef00d"';
        expect(ConditionalGet::isNotModified($header, null, $etag, 1000))->toBeTrue();
    });

    test('wildcard * always matches', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        expect(ConditionalGet::isNotModified('*', null, $etag, 1000))->toBeTrue();
    });

    test('takes precedence over If-Modified-Since when both are present', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        $other = ConditionalGet::etag(1000, 999);
        // If-Modified-Since alone would say "not modified" (mtime <= since), but a
        // mismatched If-None-Match must win per RFC 9110 §13.1.1.
        expect(ConditionalGet::isNotModified($other, gmdate('D, d M Y H:i:s', 5000) . ' GMT', $etag, 1000))->toBeFalse();
    });
});

describe('ConditionalGet::isNotModified() — If-Modified-Since', function (): void {
    test('not modified when mtime is at or before the given date', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        $since = gmdate('D, d M Y H:i:s', 1000) . ' GMT';
        expect(ConditionalGet::isNotModified(null, $since, $etag, 1000))->toBeTrue();
    });

    test('modified when mtime is after the given date', function (): void {
        $etag = ConditionalGet::etag(2000, 500);
        $since = gmdate('D, d M Y H:i:s', 1000) . ' GMT';
        expect(ConditionalGet::isNotModified(null, $since, $etag, 2000))->toBeFalse();
    });

    test('an unparsable date is treated as modified', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        expect(ConditionalGet::isNotModified(null, 'not-a-date', $etag, 1000))->toBeFalse();
    });
});

describe('ConditionalGet::isNotModified() — no validators', function (): void {
    test('modified when neither header is present', function (): void {
        $etag = ConditionalGet::etag(1000, 500);
        expect(ConditionalGet::isNotModified(null, null, $etag, 1000))->toBeFalse();
    });
});
