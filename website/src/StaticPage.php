<?php

declare(strict_types=1);

namespace PhpVia\Website;

use Mbolli\PhpVia\Context;

/**
 * A page whose markup never changes after it is served. Updates render nothing, so the SSE
 * connect and every broadcast reaching its scope stop resending the whole document; its
 * components still patch themselves.
 */
final class StaticPage {
    /**
     * @param array<string, mixed>|(\Closure(): array<string, mixed>) $data template data, or a closure that
     *                                                                      builds it at render time (for component renders)
     */
    public static function view(Context $c, string $template, array|\Closure $data = []): void {
        // cacheUpdates: false, because the update cache is keyed by scope and an empty render
        // stored there would replace the update of a component that shares the scope.
        $c->view(
            static fn (bool $isUpdate): string => $isUpdate ? '' : $c->render($template, $data instanceof \Closure ? $data() : $data),
            cacheUpdates: false,
        );
    }
}
