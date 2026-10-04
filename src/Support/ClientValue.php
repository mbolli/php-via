<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * The types a signal takes from the browser, and the check of a value the browser sent.
 *
 * Types are 'null', 'bool', 'int', 'float', 'string' and 'array'. A value of another type is taken
 * only in a lossless form of one of them, which is what Datastar's bindings send for a typed signal:
 * a <textarea> or a radio group writes strings, a checkbox on a number signal writes a boolean.
 *
 * @internal
 */
final class ClientValue {
    /**
     * The types of a signal declared with $initial, null for any type: a number takes int and float,
     * and null or an object declares no type.
     *
     * @return null|list<string>
     */
    public static function typesOf(mixed $initial): ?array {
        return match (true) {
            \is_int($initial), \is_float($initial) => ['int', 'float'],
            \is_bool($initial) => ['bool'],
            \is_string($initial) => ['string'],
            \is_array($initial) => ['array'],
            default => null,
        };
    }

    /**
     * The types a property's declared type takes, null for one that takes any (mixed). A class type
     * takes nothing from the browser but null, if nullable.
     *
     * @return null|false|list<string> false when the property declares no type
     */
    public static function typesOfProperty(\ReflectionProperty $property): array|false|null {
        $type = $property->getType();
        if ($type === null) {
            return false;
        }

        $named = $type instanceof \ReflectionUnionType ? $type->getTypes() : [$type];
        $types = [];
        foreach ($named as $part) {
            if (!$part instanceof \ReflectionNamedType) {
                return [];
            }
            if ($part->getName() === 'mixed') {
                return null;
            }
            $types = [...$types, ...match ($part->getName()) {
                'int' => ['int'],
                'float' => ['int', 'float'],
                'bool', 'true', 'false' => ['bool'],
                'string' => ['string'],
                'array' => ['array'],
                'null' => ['null'],
                default => [],
            }];
        }
        if ($type->allowsNull()) {
            $types[] = 'null';
        }

        return array_values(array_unique($types));
    }

    /**
     * $value as one of $types, wrapped in a list, or null when it is none of them.
     *
     * @param list<string> $types
     *
     * @return null|array{mixed}
     */
    public static function coerce(mixed $value, array $types): ?array {
        if (\in_array(get_debug_type($value), $types, true)) {
            return [$value];
        }

        foreach ($types as $type) {
            $coerced = match ($type) {
                'int' => self::toInt($value),
                'float' => \is_int($value) || \is_bool($value) || (\is_string($value) && is_numeric($value)) ? [(float) $value] : null,
                'bool' => self::toBool($value),
                'string' => \is_int($value) || \is_float($value) ? [(string) $value] : null,
                default => null,
            };
            if ($coerced !== null) {
                return $coerced;
            }
        }

        return null;
    }

    /**
     * @return null|array{int}
     */
    private static function toInt(mixed $value): ?array {
        if (\is_bool($value)) {
            return [(int) $value];
        }
        if (\is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (\is_float($value) && floor($value) === $value && abs($value) < 2 ** 53) {
            return [(int) $value];
        }

        return null;
    }

    /**
     * @return null|array{bool}
     */
    private static function toBool(mixed $value): ?array {
        if ($value === 0 || $value === 1) {
            return [(bool) $value];
        }
        if (\is_string($value)) {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $bool === null ? null : [$bool];
        }

        return null;
    }
}
