<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * @internal
 */
final class Removed {
    public static function method(string $old, string $replacement): never {
        throw new \BadMethodCallException("{$old} was removed in php-via 0.14. {$replacement}");
    }
}
