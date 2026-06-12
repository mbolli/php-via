<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a property as a SESSION-scoped signal.
 *
 * - Shared across all browser tabs belonging to the same user session
 * - Server-authoritative — the client cannot write to it directly
 * - Auto-injected into Twig templates as a Signal object
 * - Auto-broadcasts to all user tabs when the value changes
 *
 * Signal names are global within the SESSION scope. Two pages using the same
 * property name share the same session signal — use unique names intentionally.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class StateSess {}
