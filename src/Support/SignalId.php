<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Builds the id a signal has in the browser.
 *
 * The id starts with the old sanitised form, in which every byte outside [A-Za-z0-9] is "_". When
 * that form is not one of the plain shapes that read back unambiguously, or equals a RESERVED id,
 * "____" and one code per "_" follow, so no two signals share an id.
 *
 * @internal
 */
final class SignalId {
    /** Browser signals the framework owns. Only a plain id can equal one, and it then gets the suffix. */
    public const array RESERVED = ['via_ctx', '_disconnected'];

    private const string MARK = '____';

    private const string SCOPE_JOINER = 'k';
    private const string NAMESPACE_JOINER = 'n';
    private const string CONTEXT_JOINER = 't';

    /** Any other byte is coded as "x" and two lowercase hex digits. */
    private const array BYTE_CODES = ['_' => 'u', ':' => 'c', '/' => 's', '-' => 'd', '.' => 'p'];

    private const string PLAIN_NAME = '/^[A-Za-z0-9]+\z/';
    private const string PLAIN_SCOPE = '#^(?:[A-Za-z0-9]+(?::[A-Za-z0-9]+)*|route:/(?:[A-Za-z0-9]+(?:/[A-Za-z0-9]+)*)?)\z#';

    public static function scoped(string $scope, ?string $namespace, string $name): string {
        if ($namespace !== null) {
            return self::build([['', $scope], [self::SCOPE_JOINER, $namespace], [self::NAMESPACE_JOINER, $name]], false);
        }

        $parts = [['', $scope], [self::SCOPE_JOINER, $name]];
        $plain = preg_match(self::PLAIN_NAME, $name) === 1 && preg_match(self::PLAIN_SCOPE, $scope) === 1;
        $id = self::build($parts, $plain);

        return $plain && \in_array($id, self::RESERVED, true) ? self::build($parts, false) : $id;
    }

    /**
     * @param null|string $namespace the component namespace, or null for a page signal
     */
    public static function tab(?string $namespace, string $name, string $contextId): string {
        return $namespace !== null
            ? self::build([['', $namespace], [self::NAMESPACE_JOINER, $name]], false)
            : self::build([['', $name], [self::CONTEXT_JOINER, $contextId]], false);
    }

    /**
     * @param list<array{0: string, 1: string}> $parts joiner code ('' for the first part) and raw text
     */
    private static function build(array $parts, bool $plain): string {
        $id = '';
        $codes = '';
        foreach ($parts as [$joiner, $text]) {
            if ($joiner !== '') {
                $id .= '_';
                $codes .= $joiner;
            }
            // Byte-wise on purpose (no /u): each byte of a multibyte character gets its own code.
            $id .= (string) preg_replace_callback('/[^A-Za-z0-9]/', static function (array $m) use (&$codes): string {
                $codes .= self::BYTE_CODES[$m[0]] ?? 'x' . bin2hex($m[0]);

                return '_';
            }, $text);
        }

        return $plain ? $id : $id . self::MARK . $codes;
    }
}
