<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Attributes;

use Mbolli\PhpVia\Scope;

/**
 * Marks a property as a reactive signal — synced to the browser and
 * auto-injected into Twig templates as a Signal object.
 *
 * The scope controls who shares the value and who receives updates:
 *
 * - Scope::TAB (default) — isolated per browser tab, client-writable via data-bind
 * - Scope::ROUTE         — shared across all users on the same route
 * - Scope::SESSION       — shared across all tabs of the same browser session
 * - Scope::GLOBAL        — shared across ALL users and tabs
 * - custom string        — shared across all contexts in that scope (e.g. "room:lobby")
 *
 * Non-TAB scopes are server-authoritative (the client cannot write them directly)
 * and auto-broadcast to every context in the scope when the value changes.
 *
 * @example
 * #[Signal]
 * public int $count = 0;                    // TAB — private per tab
 *
 * #[Signal(Scope::ROUTE)]
 * public int $sharedCounter = 0;            // ROUTE — shared on this route
 *
 * #[Signal(Scope::SESSION)]
 * public string $username = 'Anonymous';    // SESSION — shared across user's tabs
 *
 * #[Signal(Scope::GLOBAL)]
 * public int $totalVisitors = 0;            // GLOBAL — shared across all users
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Signal {
    public function __construct(
        /** Signal scope. Defaults to Scope::TAB (isolated per browser tab). */
        public readonly string $scope = Scope::TAB,
    ) {}
}
