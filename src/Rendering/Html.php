<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * Trusted markup that a context passes to its template engine, which prints it unescaped: an
 * autoescaping engine marks this class safe, as TwigEngine does.
 */
final class Html implements \Stringable {
    /** @var \Closure(): string|string */
    private \Closure|string $html;

    /**
     * @internal php-via builds these
     *
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
