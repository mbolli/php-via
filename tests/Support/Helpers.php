<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Helper class for render counting in tests.
 */
class RenderCounter {
    public int $count = 0;

    public function __invoke(): string {
        ++$this->count;

        return '<div>Render ' . $this->count . '</div>';
    }

    public function withPrefix(string $prefix): callable {
        return function () use ($prefix) {
            ++$this->count;

            return '<div>' . $prefix . ' ' . $this->count . '</div>';
        };
    }
}
