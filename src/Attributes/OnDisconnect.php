<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a method as the disconnect handler for a composition page/component.
 *
 * The method is registered via Context::onDisconnect() and runs when the SSE
 * connection closes and stays closed (or the browser sends a close beacon).
 * It receives the Context as its only argument. At most one method per class
 * may carry this attribute.
 *
 * Instance reactive properties are NOT re-hydrated before the handler runs —
 * disconnect handlers typically do cleanup (presence updates, broadcasts)
 * rather than read live signal values.
 *
 * @example
 * #[OnDisconnect]
 * public function leave(Context $ctx): void {
 *     Room::$members[$this->room]--;
 *     $ctx->broadcast();
 * }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class OnDisconnect {}
