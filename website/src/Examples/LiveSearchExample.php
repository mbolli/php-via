<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

final class LiveSearchExample {
    public const string SLUG = 'live-search';

    /** @var list<array{name: string, category: string, desc: string}> */
    private const array FUNCTIONS = [
        // Array
        ['name' => 'array_map', 'category' => 'Array', 'desc' => 'Applies a callback to every element and returns the result array.'],
        ['name' => 'array_filter', 'category' => 'Array', 'desc' => 'Filters elements using a callback; returns only matching entries.'],
        ['name' => 'array_reduce', 'category' => 'Array', 'desc' => 'Iteratively reduces an array to a single value via callback.'],
        ['name' => 'array_walk', 'category' => 'Array', 'desc' => 'Applies a callback to every element, optionally modifying values in place.'],
        ['name' => 'array_search', 'category' => 'Array', 'desc' => 'Searches for a value and returns its corresponding key, or false.'],
        ['name' => 'array_keys', 'category' => 'Array', 'desc' => 'Returns all keys of an array.'],
        ['name' => 'array_values', 'category' => 'Array', 'desc' => 'Returns all values of an array, re-indexed from 0.'],
        ['name' => 'array_slice', 'category' => 'Array', 'desc' => 'Extracts a portion of an array by offset and length.'],
        ['name' => 'array_splice', 'category' => 'Array', 'desc' => 'Removes elements and optionally inserts new ones in their place.'],
        ['name' => 'array_merge', 'category' => 'Array', 'desc' => 'Merges one or more arrays, re-indexing numeric keys.'],
        ['name' => 'array_unique', 'category' => 'Array', 'desc' => 'Removes duplicate values from an array.'],
        ['name' => 'array_flip', 'category' => 'Array', 'desc' => 'Exchanges keys and values of an array.'],
        ['name' => 'array_reverse', 'category' => 'Array', 'desc' => 'Returns an array with elements in reverse order.'],
        ['name' => 'array_push', 'category' => 'Array', 'desc' => 'Pushes one or more elements onto the end of an array.'],
        ['name' => 'array_pop', 'category' => 'Array', 'desc' => 'Pops the last element off an array and returns it.'],
        ['name' => 'array_shift', 'category' => 'Array', 'desc' => 'Removes and returns the first element of an array.'],
        ['name' => 'array_unshift', 'category' => 'Array', 'desc' => 'Prepends one or more elements to the front of an array.'],
        ['name' => 'array_chunk', 'category' => 'Array', 'desc' => 'Splits an array into chunks of a given size.'],
        ['name' => 'array_combine', 'category' => 'Array', 'desc' => 'Creates an array using one array as keys and another as values.'],
        ['name' => 'array_diff', 'category' => 'Array', 'desc' => 'Computes the difference of arrays — values in first but not the rest.'],
        ['name' => 'array_intersect', 'category' => 'Array', 'desc' => 'Computes the intersection of arrays — values present in all.'],
        ['name' => 'in_array', 'category' => 'Array', 'desc' => 'Checks whether a given value exists in an array.'],
        ['name' => 'count', 'category' => 'Array', 'desc' => 'Counts elements in an array or countable object.'],
        ['name' => 'sort', 'category' => 'Array', 'desc' => 'Sorts an array by value in ascending order.'],
        ['name' => 'rsort', 'category' => 'Array', 'desc' => 'Sorts an array by value in descending order.'],
        ['name' => 'asort', 'category' => 'Array', 'desc' => 'Sorts by value, maintaining key-value associations.'],
        ['name' => 'ksort', 'category' => 'Array', 'desc' => 'Sorts an array by key in ascending order.'],
        ['name' => 'usort', 'category' => 'Array', 'desc' => 'Sorts by value using a user-defined comparison function.'],
        ['name' => 'uasort', 'category' => 'Array', 'desc' => 'Sorts preserving index association with a user-defined comparison.'],
        ['name' => 'uksort', 'category' => 'Array', 'desc' => 'Sorts by keys using a user-defined comparison function.'],
        ['name' => 'compact', 'category' => 'Array', 'desc' => 'Creates an array from variables, using their names as keys.'],
        ['name' => 'extract', 'category' => 'Array', 'desc' => 'Imports variables from an array into the local symbol table.'],
        // String
        ['name' => 'str_contains', 'category' => 'String', 'desc' => 'Returns true if the string contains the given substring.'],
        ['name' => 'str_starts_with', 'category' => 'String', 'desc' => 'Checks whether a string begins with a given prefix.'],
        ['name' => 'str_ends_with', 'category' => 'String', 'desc' => 'Checks whether a string ends with a given suffix.'],
        ['name' => 'str_replace', 'category' => 'String', 'desc' => 'Replaces all occurrences of a search string with a replacement.'],
        ['name' => 'str_pad', 'category' => 'String', 'desc' => 'Pads a string to a specified length with another string.'],
        ['name' => 'str_split', 'category' => 'String', 'desc' => 'Splits a string into an array of characters or chunks.'],
        ['name' => 'str_repeat', 'category' => 'String', 'desc' => 'Returns a string repeated a given number of times.'],
        ['name' => 'str_word_count', 'category' => 'String', 'desc' => 'Counts the number of words in a string.'],
        ['name' => 'strlen', 'category' => 'String', 'desc' => 'Returns the byte length of a string.'],
        ['name' => 'mb_strlen', 'category' => 'String', 'desc' => 'Returns the character length of a multibyte string.'],
        ['name' => 'substr', 'category' => 'String', 'desc' => 'Returns part of a string, starting at offset for a given length.'],
        ['name' => 'mb_substr', 'category' => 'String', 'desc' => 'Returns part of a multibyte string — safe for Unicode.'],
        ['name' => 'strpos', 'category' => 'String', 'desc' => 'Finds the byte-position of the first occurrence of a substring.'],
        ['name' => 'strrpos', 'category' => 'String', 'desc' => 'Finds the byte-position of the last occurrence of a substring.'],
        ['name' => 'strtolower', 'category' => 'String', 'desc' => 'Converts a string to lowercase.'],
        ['name' => 'strtoupper', 'category' => 'String', 'desc' => 'Converts a string to uppercase.'],
        ['name' => 'ucfirst', 'category' => 'String', 'desc' => 'Uppercases the first character of a string.'],
        ['name' => 'ucwords', 'category' => 'String', 'desc' => 'Uppercases the first character of each word.'],
        ['name' => 'lcfirst', 'category' => 'String', 'desc' => 'Lowercases the first character of a string.'],
        ['name' => 'trim', 'category' => 'String', 'desc' => 'Strips whitespace (or other characters) from both ends.'],
        ['name' => 'ltrim', 'category' => 'String', 'desc' => 'Strips whitespace from the beginning of a string.'],
        ['name' => 'rtrim', 'category' => 'String', 'desc' => 'Strips whitespace from the end of a string.'],
        ['name' => 'explode', 'category' => 'String', 'desc' => 'Splits a string by a delimiter into an array.'],
        ['name' => 'implode', 'category' => 'String', 'desc' => 'Joins array elements together with a glue string.'],
        ['name' => 'sprintf', 'category' => 'String', 'desc' => 'Returns a formatted string using printf-style format specifiers.'],
        ['name' => 'number_format', 'category' => 'String', 'desc' => 'Formats a number with grouped thousands and decimal separator.'],
        ['name' => 'preg_match', 'category' => 'String', 'desc' => 'Performs a regular expression match; returns the match count.'],
        ['name' => 'preg_replace', 'category' => 'String', 'desc' => 'Performs a regular expression search and replace.'],
        ['name' => 'preg_split', 'category' => 'String', 'desc' => 'Splits a string by a regular expression pattern.'],
        ['name' => 'htmlspecialchars', 'category' => 'String', 'desc' => 'Converts special characters to HTML entity equivalents.'],
        ['name' => 'strip_tags', 'category' => 'String', 'desc' => 'Removes HTML and PHP tags from a string.'],
        ['name' => 'nl2br', 'category' => 'String', 'desc' => 'Inserts <br> line-break tags before all newlines in a string.'],
        ['name' => 'wordwrap', 'category' => 'String', 'desc' => 'Wraps a string to a given number of characters.'],
        // Math
        ['name' => 'abs', 'category' => 'Math', 'desc' => 'Returns the absolute value of a number.'],
        ['name' => 'ceil', 'category' => 'Math', 'desc' => 'Rounds a float up to the nearest integer.'],
        ['name' => 'floor', 'category' => 'Math', 'desc' => 'Rounds a float down to the nearest integer.'],
        ['name' => 'round', 'category' => 'Math', 'desc' => 'Rounds a float to the nearest integer (or given decimal places).'],
        ['name' => 'min', 'category' => 'Math', 'desc' => 'Returns the smallest value from arguments or an array.'],
        ['name' => 'max', 'category' => 'Math', 'desc' => 'Returns the largest value from arguments or an array.'],
        ['name' => 'rand', 'category' => 'Math', 'desc' => 'Generates a pseudo-random integer between min and max.'],
        ['name' => 'mt_rand', 'category' => 'Math', 'desc' => 'Faster pseudo-random integer via Mersenne Twister algorithm.'],
        ['name' => 'random_int', 'category' => 'Math', 'desc' => 'Generates a cryptographically secure random integer.'],
        ['name' => 'pow', 'category' => 'Math', 'desc' => 'Returns base raised to the power of exp.'],
        ['name' => 'sqrt', 'category' => 'Math', 'desc' => 'Returns the square root of a number.'],
        ['name' => 'log', 'category' => 'Math', 'desc' => 'Returns the natural logarithm (or log to a custom base).'],
        ['name' => 'fmod', 'category' => 'Math', 'desc' => 'Returns the floating-point remainder of a division.'],
        ['name' => 'intdiv', 'category' => 'Math', 'desc' => 'Returns the integer quotient of a division.'],
        ['name' => 'pi', 'category' => 'Math', 'desc' => 'Returns the value of π (approx. 3.14159).'],
        // Date
        ['name' => 'date', 'category' => 'Date', 'desc' => 'Formats a Unix timestamp as a date/time string.'],
        ['name' => 'time', 'category' => 'Date', 'desc' => 'Returns the current Unix timestamp as an integer.'],
        ['name' => 'mktime', 'category' => 'Date', 'desc' => 'Returns Unix timestamp for a given date and time.'],
        ['name' => 'strtotime', 'category' => 'Date', 'desc' => 'Parses a date/time string (e.g. "next Monday") into a Unix timestamp.'],
        ['name' => 'microtime', 'category' => 'Date', 'desc' => 'Returns the current Unix timestamp with microseconds.'],
        ['name' => 'checkdate', 'category' => 'Date', 'desc' => 'Validates a Gregorian date given month, day, and year.'],
        ['name' => 'date_create', 'category' => 'Date', 'desc' => 'Creates a new DateTime object from a date string.'],
        ['name' => 'date_format', 'category' => 'Date', 'desc' => 'Formats a DateTime object into a localized date string.'],
        ['name' => 'date_diff', 'category' => 'Date', 'desc' => 'Returns the difference between two DateTime objects.'],
        ['name' => 'date_add', 'category' => 'Date', 'desc' => 'Adds a DateInterval to a DateTime object.'],
        ['name' => 'date_sub', 'category' => 'Date', 'desc' => 'Subtracts a DateInterval from a DateTime object.'],
        ['name' => 'date_modify', 'category' => 'Date', 'desc' => 'Alters a DateTime timestamp using a relative date string.'],
        // Data
        ['name' => 'json_encode', 'category' => 'Data', 'desc' => 'Encodes a PHP value into a JSON-formatted string.'],
        ['name' => 'json_decode', 'category' => 'Data', 'desc' => 'Decodes a JSON string into a PHP value (or null on failure).'],
        ['name' => 'serialize', 'category' => 'Data', 'desc' => 'Generates a storable representation of any PHP value.'],
        ['name' => 'unserialize', 'category' => 'Data', 'desc' => 'Creates a PHP value from a serialized representation.'],
        ['name' => 'base64_encode', 'category' => 'Data', 'desc' => 'Encodes binary data using the MIME Base64 scheme.'],
        ['name' => 'base64_decode', 'category' => 'Data', 'desc' => 'Decodes data encoded with the MIME Base64 scheme.'],
        ['name' => 'md5', 'category' => 'Data', 'desc' => 'Calculates the MD5 hash of a string (not safe for passwords).'],
        ['name' => 'sha1', 'category' => 'Data', 'desc' => 'Calculates the SHA1 hash of a string.'],
        ['name' => 'hash', 'category' => 'Data', 'desc' => 'Generates a hash value with a named algorithm (SHA-256, etc.).'],
        ['name' => 'hash_hmac', 'category' => 'Data', 'desc' => 'Generates a keyed hash using the HMAC method.'],
        ['name' => 'crc32', 'category' => 'Data', 'desc' => 'Calculates the 32-bit CRC checksum of a string.'],
        // Misc
        ['name' => 'isset', 'category' => 'Misc', 'desc' => 'Returns true if a variable is set and not null.'],
        ['name' => 'empty', 'category' => 'Misc', 'desc' => 'Returns true if a variable is empty (0, "", null, false, []).'],
        ['name' => 'unset', 'category' => 'Misc', 'desc' => 'Destroys one or more specified variables.'],
        ['name' => 'var_dump', 'category' => 'Misc', 'desc' => 'Dumps detailed type and value information about a variable.'],
        ['name' => 'var_export', 'category' => 'Misc', 'desc' => 'Outputs or returns a parsable PHP representation of a value.'],
        ['name' => 'print_r', 'category' => 'Misc', 'desc' => 'Prints human-readable information about a variable.'],
        ['name' => 'gettype', 'category' => 'Misc', 'desc' => 'Returns the type of a variable as a string.'],
        ['name' => 'get_debug_type', 'category' => 'Misc', 'desc' => 'Returns the debug type — class name for objects, PHP type for scalars.'],
        ['name' => 'is_array', 'category' => 'Misc', 'desc' => 'Checks whether a variable is an array.'],
        ['name' => 'is_string', 'category' => 'Misc', 'desc' => 'Checks whether a variable is a string.'],
        ['name' => 'is_int', 'category' => 'Misc', 'desc' => 'Checks whether a variable is an integer.'],
        ['name' => 'is_float', 'category' => 'Misc', 'desc' => 'Checks whether a variable is a float.'],
        ['name' => 'is_bool', 'category' => 'Misc', 'desc' => 'Checks whether a variable is a boolean.'],
        ['name' => 'is_null', 'category' => 'Misc', 'desc' => 'Checks whether a variable is null.'],
        ['name' => 'is_numeric', 'category' => 'Misc', 'desc' => 'Checks whether a variable is numeric or a numeric string.'],
        ['name' => 'is_callable', 'category' => 'Misc', 'desc' => 'Checks whether a value can be called as a function.'],
        ['name' => 'function_exists', 'category' => 'Misc', 'desc' => 'Returns true if the named function is defined.'],
        ['name' => 'class_exists', 'category' => 'Misc', 'desc' => 'Returns true if the named class is defined.'],
        ['name' => 'method_exists', 'category' => 'Misc', 'desc' => 'Returns true if a method exists on an object or class.'],
    ];

    /** @var list<string> */
    private const array CATEGORIES = ['all', 'Array', 'String', 'Math', 'Date', 'Data', 'Misc'];

    public static function register(Via $app): void {
        $app->page('/examples/live-search', function (Context $c): void {
            $c->signal('', 'query');
            $c->signal('all', 'category');

            $c->action(function (Context $ctx): void {
                $cat = $ctx->input('cat');
                $category = $ctx->getSignal('category');

                if ($cat !== null && \in_array($cat, self::CATEGORIES, strict: true)) {
                    $category->setValue($cat, broadcast: false);
                }

                $ctx->sync();
            }, 'search');

            $c->view(fn (): string => $c->render('examples/live_search.html.twig', [
                'title' => '🔍 Live Search',
                'description' => 'Type to filter PHP stdlib functions server-side. Every keystroke is a round-trip — and nobody shipped a line of search logic to the browser.',
                'summary' => [
                    '<strong>data-on:input__throttle.100ms.trailing</strong> fires at most once every 100 ms while the user types, and always fires one final time at the end — so the last keystroke is never dropped. No setTimeout written by you.',
                    '<strong>Server-side filtering</strong> is intentional. The query parser, category filter, and result ranking all live in PHP. Swap the hardcoded array for a database query and nothing else changes.',
                    '<strong>$c->sync()</strong> re-renders the view for this tab only — no broadcast, no shared state. Other users\' searches are completely isolated.',
                    '<strong>Signals are injected</strong> into the context before the action closure runs. By the time the view callable executes, $query->string() already holds the current input value.',
                    '<strong>Callable views</strong> re-run on every sync, computing fresh results. The client receives rendered HTML via SSE — no JSON payload, no client-side fetch() logic.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'query', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Current text in the search box. Injected from the browser before the action closure runs.'],
                        ['name' => 'category', 'type' => 'string', 'scope' => 'TAB', 'default' => '"all"', 'desc' => 'Active category filter. Set via ?cat= query param when the user clicks a filter pill.'],
                    ],
                    'actions' => [
                        ['name' => 'search', 'desc' => 'Reads optional ?cat= param to override category, then calls $c->sync() to re-render the results block.'],
                    ],
                    'views' => [
                        ['name' => 'live_search.html.twig', 'desc' => 'Callable view — re-runs on every sync, filtering the PHP stdlib dataset from current signal values. Only the results block is re-rendered on updates.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LiveSearchExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/live_search.html.twig'],
                ],
                'results' => self::filter($c->getSignal('query')->string(), $c->getSignal('category')->string()),
                'categories' => self::CATEGORIES,
            ]), block: 'results', cacheUpdates: false);
        });
    }

    /**
     * @return list<array{name: string, category: string, desc: string}>
     */
    private static function filter(string $query, string $category): array {
        $q = mb_strtolower(trim($query));
        $cat = trim($category);

        $results = self::FUNCTIONS;

        if ($cat !== '' && $cat !== 'all') {
            $results = array_values(
                array_filter($results, static fn (array $f): bool => $f['category'] === $cat)
            );
        }

        if ($q !== '') {
            $results = array_values(
                array_filter(
                    $results,
                    static fn (array $f): bool => str_contains($f['name'], $q)
                        || str_contains(mb_strtolower($f['desc']), $q)
                )
            );
        }

        return $results;
    }
}
