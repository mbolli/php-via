<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a method as the cleanup handler for a composition page/component.
 *
 * The method is registered via Context::onCleanup() and runs when the context
 * is disposed. It receives the Context as its only argument. At most one method
 * per class may carry this attribute.
 *
 * Context::onDisconnect() and Context::onCleanup() share the same underlying
 * cleanup-callback queue; use #[OnCleanup] when you prefer the cleanup-oriented
 * naming, and #[OnDisconnect] for disconnect-oriented semantics.
 *
 * @example
 * #[OnCleanup]
 * public function dispose(Context $ctx): void {
 *     $this->repository->close();
 * }
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class OnCleanup {}
