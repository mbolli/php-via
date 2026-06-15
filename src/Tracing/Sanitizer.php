<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Tracing;

/**
 * Redacts sensitive span attributes and truncates oversized values before
 * traces leave the server.
 *
 * The dev bar exposes attribute key/values to anyone who can reach the page
 * (including the public website demo). Keys that look like credentials are
 * replaced with a placeholder; long values are clipped so a stray request body
 * can't bloat the buffer or leak in full.
 */
final class Sanitizer {
    /** Keys matching this pattern have their value replaced with REDACTED. */
    private const string REDACT_PATTERN = '/pass(word)?|token|secret|authorization|cookie|api[_-]?key|\bkey\b|credential/i';

    private const string REDACTED = '[redacted]';

    /** Maximum length of a stringified attribute value before truncation. */
    private const int MAX_VALUE_LENGTH = 512;

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function sanitizeAttributes(array $attributes): array {
        $out = [];
        foreach ($attributes as $key => $value) {
            if (preg_match(self::REDACT_PATTERN, (string) $key) === 1) {
                $out[$key] = self::REDACTED;

                continue;
            }
            $out[$key] = self::sanitizeValue($value);
        }

        return $out;
    }

    /**
     * Clip strings and JSON-encode non-scalars, truncating to MAX_VALUE_LENGTH.
     */
    public static function sanitizeValue(mixed $value): mixed {
        if (\is_string($value)) {
            return self::truncate($value);
        }

        if (\is_scalar($value) || $value === null) {
            return $value;
        }

        $json = json_encode($value);
        if ($json === false) {
            return '[unserializable]';
        }

        return self::truncate($json);
    }

    private static function truncate(string $value): string {
        if (\strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }

        return substr($value, 0, self::MAX_VALUE_LENGTH) . '…(' . \strlen($value) . 'B)';
    }
}
