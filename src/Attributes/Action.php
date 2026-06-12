<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a method as a client-callable action.
 *
 * Only methods annotated with #[Action] are registered — this is opt-in
 * to prevent accidentally exposing utility methods.
 *
 * @example
 * #[Action]
 * public function increment(Context $ctx): void { $this->count++; }
 *
 * #[Action(name: 'do-reset')]          // custom action URL slug
 * public function reset(Context $ctx): void { $this->count = 0; }
 *
 * #[Action(scope: Scope::SESSION)]     // session-scoped shared action
 * public function saveName(Context $ctx): void { ... }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class Action {
    public function __construct(
        /** Override the action name used in the URL (defaults to the method name). */
        public readonly ?string $name = null,
        /** Register as a scoped action (e.g. Scope::SESSION). Defaults to TAB scope. */
        public readonly ?string $scope = null,
    ) {}
}
