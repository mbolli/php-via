<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a property as server-only state that persists between action calls.
 *
 * - NOT sent to the client — invisible to the browser
 * - No Signal is created
 * - The value survives across action calls because the class instance is kept
 *   alive on the Context for the lifetime of the browser tab
 *
 * Use it for server-side accumulators, flags, route params stored in view(),
 * or any intermediary state the browser must not see.
 *
 * @example
 * #[Persist]
 * public int $multiplier = 1;   // grows across clicks; never reaches the client
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Persist {}
