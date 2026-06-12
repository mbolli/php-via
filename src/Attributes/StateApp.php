<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

/**
 * Marks a property as a GLOBAL-scoped signal.
 *
 * - Shared across ALL users and all browser tabs
 * - Server-authoritative — the client cannot write to it directly
 * - Auto-injected into Twig templates as a Signal object
 * - Auto-broadcasts to all connected users when the value changes
 *
 * Signal names are global across the entire app. Two pages using the same
 * property name share the same global signal — use unique names intentionally.
 *
 * Note: concurrent mutations in different coroutines can race if the action
 * contains async I/O (sleep, Redis calls, etc.). Keep StateApp mutations
 * in synchronous actions or use explicit locking.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class StateApp {}
