<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

final class TypeCaster {
    public static function cast(string $value, string $type): mixed {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
