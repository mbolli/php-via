<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Builds the id of the element that wraps a component. Updates target it as "#<id>", so every byte
 * outside [A-Za-z0-9-], such as the braces of a route pattern in the context id, becomes "-".
 *
 * @internal
 */
final class DomId {
    public static function component(string $contextId): string {
        return 'c-' . (preg_replace('/[^A-Za-z0-9-]/', '-', $contextId) ?? '');
    }
}
