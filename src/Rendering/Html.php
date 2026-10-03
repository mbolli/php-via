<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * Trusted markup that a context passes to its template engine, which prints it unescaped.
 *
 * @internal
 */
final class Html implements \Stringable {
    /** @var \Closure(): string|string */
    private \Closure|string $html;

    /**
     * @param \Closure(): string|string $html the markup, or a closure that builds it on first use
     */
    public function __construct(\Closure|string $html) {
        $this->html = $html;
    }

    public function __toString(): string {
        if ($this->html instanceof \Closure) {
            $this->html = ($this->html)();
        }

        return $this->html;
    }
}
