<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a property as a TAB-scoped reactive signal.
 *
 * - Client-visible and client-writable (via data-bind)
 * - Isolated per browser tab
 * - Auto-injected into Twig templates as a Signal object
 *
 * The property's default value (= 0, = '', etc.) is used as the initial signal value.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Signal {}
