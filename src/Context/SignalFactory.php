<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Support\SignalId;
use Mbolli\PhpVia\Via;

/**
 * SignalFactory - Handles signal creation and management.
 *
 * Manages:
 * - Signal creation logic
 * - Scope determination
 * - Signal ID generation
 * - TAB vs scoped signal handling
 */
class SignalFactory {
    /** @var array<string, Signal> */
    private array $signals = [];

    /** @var array<string, Signal> Map of user-supplied signal name → Signal (all scopes) */
    private array $signalNameMap = [];

    /** @var array<string, true> Re-declaration warnings already logged, keyed by kind and name */
    private array $redeclarationWarned = [];

    public function __construct(
        private Context $context,
        private Via $app,
    ) {}

    /**
     * Create a signal.
     *
     * @param mixed       $initialValue   The initial value of the signal
     * @param null|string $name           Optional signal name (defaults to 'signal')
     * @param null|string $scope          Optional scope for shared signal (null = TAB scope, no sharing)
     * @param bool        $autoBroadcast  Auto-broadcast changes for scoped signals (default: true)
     * @param null|bool   $clientWritable Whether the client may write it; null picks the scope's default
     *
     * TAB scope (scope=null): Signal is private to this context, not shared
     * ROUTE/SESSION/GLOBAL scope: Signal is shared across all contexts in the same scope
     * Custom scope: Signal is shared across all contexts with that scope (e.g., "room:lobby")
     */
    public function createSignal(mixed $initialValue, ?string $name = null, ?string $scope = null, bool $autoBroadcast = true, ?bool $clientWritable = null): Signal {
        $baseName = $name ?? 'signal';

        // If no explicit scope provided, inherit from context's primary scope
        if ($scope === null) {
            $contextScope = $this->context->getPrimaryScope();
            // Only inherit if context has a non-TAB scope
            if ($contextScope !== Scope::TAB) {
                $scope = $contextScope;
            }
        }

        // Resolve ROUTE scope to the route-qualified scope ("route:/path").
        // Without this an explicit Scope::ROUTE stays the literal string "route",
        // which no context ever belongs to — so syncScopedSignals() (which walks the
        // context's own scopes) never finds the signal and no patch is ever emitted.
        // The literal bucket is also shared across every route, so two routes using
        // this form collide on signal ids.
        if ($scope === Scope::ROUTE) {
            $scope = Scope::routeScope($this->context->getRoute());
        }

        // Resolve SESSION scope to actual session ID
        if ($scope === Scope::SESSION) {
            $sessionId = $this->context->getSessionId();
            if ($sessionId === null) {
                throw new \RuntimeException('Cannot use SESSION scope without session ID');
            }
            $scope = 'session:' . $sessionId;
        }

        // For scoped signals, use scope + name as ID (no context ID needed - they're shared)
        // For TAB signals, use context ID to make them unique per context
        if ($scope !== null && $scope !== Scope::TAB) {
            // Scoped signal: shared across contexts in this scope. The component namespace is part
            // of the id, so sibling instances (cats, dogs) get independent but shared counters.
            $signalId = SignalId::scoped($scope, $this->context->getNamespace(), $baseName);

            // Check if signal already exists in this scope
            $existingSignal = $this->app->getScopedSignal($scope, $signalId);
            if ($existingSignal !== null) {
                // Signal already exists in this scope — return it without modification.
                // The initial value is only used on first creation; subsequent calls
                // (e.g. when a second context joins the scope, or on view re-render)
                // must not overwrite the live value with a potentially stale initialValue.
                // To mutate a scoped signal, call $signal->setValue() explicitly.
                $this->signalNameMap[$baseName] = $existingSignal;

                return $existingSignal;
            }

            // Create new scoped signal with Via reference for auto-broadcast
            $signal = new Signal($signalId, $initialValue, $scope, $autoBroadcast, $clientWritable, $this->app);

            // Register in Via's scoped signals
            $this->app->registerScopedSignal($scope, $signal);

            $this->signalNameMap[$baseName] = $signal;

            return $signal;
        }

        // TAB scope: context-specific signal, not shared
        // A namespace of '' or '0' counts as none here, as it always has for TAB signals.
        $signalId = SignalId::tab($this->context->getNamespace() ?: null, $baseName, $this->context->getId());

        if (isset($this->signals[$signalId])) {
            $existing = $this->signals[$signalId];
            $this->warnOnRedeclaration($existing, $baseName, $initialValue, $clientWritable);
            $existing->setValue($initialValue);
            $this->signalNameMap[$baseName] = $existing;

            return $existing;
        }

        if ($clientWritable === null && $this->app->getConfig()->getStrictTabSignals()) {
            $clientWritable = false;
        }

        $signal = new Signal($signalId, $initialValue, null, true, $clientWritable);
        $this->signals[$signalId] = $signal;
        $this->signalNameMap[$baseName] = $signal;

        return $signal;
    }

    /**
     * Get a signal by its user-supplied name.
     *
     * Works for both TAB-scoped and scoped (ROUTE/SESSION/GLOBAL/custom) signals.
     *
     * @param string $name Signal name as passed to signal()
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $name): ?Signal {
        return $this->signalNameMap[$name] ?? null;
    }

    /**
     * Get all named signals registered on this context.
     *
     * Returns signals keyed by user-supplied name (as passed to signal()).
     * Covers all scopes: TAB, ROUTE, SESSION, GLOBAL, and custom.
     *
     * @return array<string, Signal>
     */
    public function getNamedSignals(): array {
        return $this->signalNameMap;
    }

    /**
     * Get all signals available to this context.
     *
     * Returns both TAB-scoped signals (context-specific) and scoped signals
     * (shared with other contexts in the same scopes).
     *
     * @return array<string, Signal>
     */
    public function getAllSignals(): array {
        $signals = $this->signals; // TAB-scoped signals

        // Add scoped signals from all scopes this context belongs to
        foreach ($this->context->getScopes() as $scope) {
            $scopedSignals = $this->app->getScopedSignals($scope);
            foreach ($scopedSignals as $signalId => $signal) {
                $signals[$signalId] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Get TAB-scoped signals only.
     *
     * @return array<string, Signal>
     */
    public function getTabSignals(): array {
        return $this->signals;
    }

    /**
     * Whether this context declares any TAB-scoped signal at all.
     *
     * Callers use this to distinguish "all signals are clean" (a real skip
     * opportunity) from "there are no signals", which proves nothing about
     * whether the view's output can change.
     */
    public function hasSignals(): bool {
        return $this->signals !== [];
    }

    /**
     * Check if any TAB-scoped signal has changed since last sync.
     */
    public function hasChangedSignals(): bool {
        foreach ($this->signals as $signal) {
            if ($signal->hasChanged()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inject signals from the client.
     *
     * Only signals whose isClientWritable() is true take the client's value: by default TAB
     * signals do and scoped signals (ROUTE, SESSION, GLOBAL, custom) do not, and an explicit
     * clientWritable or Config::withStrictTabSignals() changes that. Ids this context does not
     * own are passed on to its component contexts.
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        $this->injectFlat($this->nestedToFlat($signalsData));
    }

    /**
     * Apply flat client values to this context's signals, then hand the rest to its components.
     *
     * @internal
     *
     * @param array<string, mixed> $flat
     */
    public function injectFlat(array $flat): void {
        foreach ($flat as $signalId => $value) {
            if (isset($this->signals[$signalId])) {
                $signal = $this->signals[$signalId];
                if ($signal->isClientWritable()) {
                    $signal->setValue($value, false);
                } elseif (!self::sameClientValue($signal->getValue(), $value)) {
                    // Re-send the server value so the browser drops its stale copy.
                    $signal->setValue($signal->getValue(), true, false);
                }
                unset($flat[$signalId]);

                continue;
            }

            foreach ($this->context->getScopes() as $scope) {
                $signal = $this->app->getScopedSignal($scope, $signalId);
                if ($signal !== null) {
                    if ($signal->isClientWritable()) {
                        $signal->setValue($value, false);
                    }
                    unset($flat[$signalId]);

                    break;
                }
            }
        }

        if ($flat === []) {
            return;
        }

        foreach ($this->context->getComponentManager()->getComponents() as $component) {
            $component->getSignalFactory()->injectFlat($flat);
        }
    }

    /**
     * Clear all signals.
     */
    public function clearSignals(): void {
        $this->signals = [];
    }

    /**
     * Warn, once per name, when a TAB re-declaration clobbers the live value or asks for a
     * different clientWritable (the first declaration's setting is kept).
     */
    private function warnOnRedeclaration(Signal $existing, string $name, mixed $initialValue, ?bool $clientWritable): void {
        $live = $existing->getValue();
        if ($live !== $initialValue && !isset($this->redeclarationWarned['value:' . $name])) {
            $this->redeclarationWarned['value:' . $name] = true;
            $this->app->log('warn', \sprintf(
                "Signal '%s' declared again with a different initial value (%s); it replaces the live value (%s)",
                $name,
                self::describeValue($initialValue),
                self::describeValue($live),
            ), $this->context);
        }

        if ($clientWritable !== null && $clientWritable !== $existing->isClientWritable() && !isset($this->redeclarationWarned['writable:' . $name])) {
            $this->redeclarationWarned['writable:' . $name] = true;
            $this->app->log('warn', \sprintf(
                "Signal '%s' declared again with clientWritable: %s; keeping the first declaration's %s",
                $name,
                var_export($clientWritable, true),
                var_export($existing->isClientWritable(), true),
            ), $this->context);
        }
    }

    /**
     * Compare a server value with the browser's copy after its JSON round trip, which turns 1.0
     * into 1 and objects into associative arrays.
     */
    private static function sameClientValue(mixed $server, mixed $client): bool {
        $json = json_encode($server);

        return ($json === false ? $server : json_decode($json, true)) === $client;
    }

    /** Type and size only: signal values are often user input and must not reach the log. */
    private static function describeValue(mixed $value): string {
        return match (true) {
            \is_string($value) => 'string of ' . mb_strlen($value) . ' chars',
            \is_array($value) => 'array of ' . \count($value) . ' items',
            default => get_debug_type($value),
        };
    }

    /**
     * Convert nested signal structure to flat
     * e.g., {"counter1": {"count": 0}} => {"counter1.count": 0}.
     *
     * @param array<int|string, mixed> $nested
     *
     * @return array<string, mixed>
     */
    private function nestedToFlat(array $nested, string $prefix = ''): array {
        $flat = [];

        foreach ($nested as $key => $value) {
            // Cast to string: a numeric top-level key (e.g. from a JSON array)
            // would otherwise be an int and violate the recursive $prefix type.
            $key = (string) $key;
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : $key;

            if (\is_array($value) && (!$this->isAssocArray($value) || $this->ownsSignalId($fullKey))) {
                // A list, or an object that is the value of a known signal rather than a namespace
                $flat[$fullKey] = $value;
            } elseif (\is_array($value)) {
                // It's an object/nested structure - recurse
                $flat = array_merge($flat, $this->nestedToFlat($value, $fullKey));
            } else {
                // It's a scalar value
                $flat[$fullKey] = $value;
            }
        }

        return $flat;
    }

    /**
     * Whether $signalId names a TAB signal of this context or one of its components, or a scoped
     * signal of its scopes.
     */
    private function ownsSignalId(string $signalId): bool {
        if (isset($this->signals[$signalId])) {
            return true;
        }

        foreach ($this->context->getScopes() as $scope) {
            if ($this->app->getScopedSignal($scope, $signalId) !== null) {
                return true;
            }
        }

        foreach ($this->context->getComponentManager()->getComponents() as $component) {
            if ($component->getSignalFactory()->ownsSignalId($signalId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if array is associative (object-like).
     *
     * @param array<int|string, mixed> $arr
     */
    private function isAssocArray(array $arr): bool {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }
}
