<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Sets the primary scope of a composition page/component class.
 *
 * The primary scope is the target of $ctx->broadcast() (with no argument) and
 * determines which contexts a plain broadcast reaches. Use it when a class
 * mutates non-signal state (static arrays, DB rows) and calls $ctx->broadcast()
 * to push a fresh view render to everyone in the scope.
 *
 * This does NOT change the scope of #[Signal] properties — each signal declares
 * its own scope independently. #[Broadcast] only affects the broadcast target.
 *
 * @example
 * #[Broadcast(Scope::ROUTE)]
 * final class TodoPage {
 *     #[Signal]                       // still TAB-scoped — unaffected by #[Broadcast]
 *     public string $draft = '';
 *
 *     #[Action]
 *     public function add(Context $ctx): void {
 *         self::$todos[] = $this->draft;
 *         $ctx->broadcast();          // reaches every context on this route
 *     }
 * }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Broadcast {
    public function __construct(
        /** Primary scope (Scope::ROUTE, Scope::GLOBAL, or a custom string). */
        public readonly string $scope,
    ) {}
}
