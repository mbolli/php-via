<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a method as a cleanup handler for a composition page/component.
 *
 * The method is registered via Context::onCleanup() and runs when the context
 * is destroyed: the SSE connection stayed closed for the cleanup delay, the
 * browser sent the close beacon, or no stream attached within the connect
 * timeout. It receives the Context as its only argument.
 *
 * Several methods may carry the attribute; they run in declaration order.
 * Reactive properties are hydrated from their signals before the first one runs.
 *
 * @example
 * #[OnCleanup]
 * public function dispose(Context $ctx): void {
 *     $this->repository->close();
 * }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class OnCleanup {}
