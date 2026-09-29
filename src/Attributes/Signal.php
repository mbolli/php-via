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
 * `clientWritable` overrides the write rule for either kind.
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
 *
 * #[Signal(clientWritable: false)]
 * public string $status = '';               // TAB, but the browser cannot overwrite it
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Signal {
    public function __construct(
        /** Signal scope. Defaults to Scope::TAB (isolated per browser tab). */
        public readonly string $scope = Scope::TAB,

        /**
         * Treat writes to this property as an ADJUSTMENT rather than an assignment.
         *
         * Integer properties only. The action's net change is applied with
         * {@see \Mbolli\PhpVia\Signal::increment()}, so `++$this->votes` on two workers at once
         * adds two instead of one. Without it the property is read before the action and
         * assigned back after, and concurrent workers each write back a result computed from
         * the same stale read.
         *
         * The trade is that assignment stops meaning assignment: `$this->votes = 0` becomes
         * "subtract whatever it currently is", which is both lossy under concurrency and
         * surprising. Reach for the signal itself when you mean to SET a value —
         * `$ctx->getSignal('votes')->setValue(0)` — which the mount leaves alone.
         *
         * Only meaningful for a shared scope; a TAB signal has no second writer to race.
         */
        public readonly bool $atomic = false,

        /**
         * Whether the browser may write this signal. null (default) keeps the scope's rule: TAB
         * is client-writable unless Config::withStrictTabSignals() is on, shared scopes are
         * server-owned. true or false applies to any scope.
         */
        public readonly ?bool $clientWritable = null,
    ) {}
}
