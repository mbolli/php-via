<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a property as server-only per-tab state.
 *
 * - NOT sent to the client — invisible to the browser
 * - Isolated per browser tab
 * - Persists across action calls for the lifetime of the Context
 *   (the class instance is kept alive on the Context)
 *
 * No Signal is created. The attribute is documentation only.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class StateTab {}
