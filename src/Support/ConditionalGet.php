<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Pure HTTP conditional-GET logic (ETag / Last-Modified) for file-backed static
 * responses. Deliberately free of OpenSwoole types so it can be unit tested
 * without a running server.
 */
final class ConditionalGet {
    /**
     * Weak ETag derived from file identity (mtime + size), not content — cheap to
     * compute on every request, matching nginx/Apache's default static-file ETag.
     */
    public static function etag(int $mtime, int $size): string {
        return 'W/"' . dechex($mtime) . '-' . dechex($size) . '"';
    }

    public static function lastModified(int $mtime): string {
        return gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    }

    /**
     * Whether the client's cached copy is still fresh. If-None-Match takes
     * precedence over If-Modified-Since per RFC 9110 §13.1.1.
     */
    public static function isNotModified(?string $ifNoneMatch, ?string $ifModifiedSince, string $etag, int $mtime): bool {
        if ($ifNoneMatch !== null) {
            return self::matchesAnyEtag($ifNoneMatch, $etag);
        }

        if ($ifModifiedSince !== null) {
            $since = strtotime($ifModifiedSince);

            return $since !== false && $mtime <= $since;
        }

        return false;
    }

    private static function matchesAnyEtag(string $header, string $etag): bool {
        if (trim($header) === '*') {
            return true;
        }

        // Weak comparison: strip an optional "W/" prefix from both sides, since
        // we only ever emit weak validators here (mtime+size, not content hash).
        $target = self::stripWeakPrefix($etag);
        foreach (explode(',', $header) as $candidate) {
            if (self::stripWeakPrefix(trim($candidate)) === $target) {
                return true;
            }
        }

        return false;
    }

    private static function stripWeakPrefix(string $value): string {
        return str_starts_with($value, 'W/') ? substr($value, 2) : $value;
    }
}
