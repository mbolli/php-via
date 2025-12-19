<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
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

    public function __construct(
        private Context $context,
        private Via $app,
    ) {}

    /**
     * Create a signal.
     *
     * @param mixed       $initialValue  The initial value of the signal
     * @param null|string $name          Optional signal name (defaults to 'signal')
     * @param null|string $scope         Optional scope for shared signal (null = TAB scope, no sharing)
     * @param bool        $autoBroadcast Auto-broadcast changes for scoped signals (default: true)
     *
     * TAB scope (scope=null): Signal is private to this context, not shared
     * ROUTE/SESSION/GLOBAL scope: Signal is shared across all contexts in the same scope
     * Custom scope: Signal is shared across all contexts with that scope (e.g., "room:lobby")
     */
    public function createSignal(mixed $initialValue, ?string $name = null, ?string $scope = null, bool $autoBroadcast = true): Signal {
        $baseName = $name ?? 'signal';

        // If no explicit scope provided, inherit from context's primary scope
        if ($scope === null) {
            $contextScope = $this->context->getPrimaryScope();
            // Only inherit if context has a non-TAB scope
            if ($contextScope !== Scope::TAB) {
                $scope = $contextScope;
            }
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
            // Scoped signal: shared across contexts in this scope
            $signalId = $scope . ':' . $baseName;
            // Sanitize signal ID - only alphanumeric and underscore allowed
            $signalId = preg_replace('/[^a-zA-Z0-9_]/', '_', $signalId);

            // Check if signal already exists in this scope
            $existingSignal = $this->app->getScopedSignal($scope, $signalId);
            if ($existingSignal !== null) {
                // Signal already exists in this scope - return it without modification
                // This ensures all contexts viewing the same scope see the same signal state
                // and prevents race conditions where each context overwrites the shared signal
                // with potentially stale or inconsistent data during re-renders.
                return $existingSignal;
            }

            // Create new scoped signal with Via reference for auto-broadcast
            $signal = new Signal($signalId, $initialValue, $scope, $autoBroadcast, $this->app);

            // Register in Via's scoped signals
            $this->app->registerScopedSignal($scope, $signal);

            return $signal;
        }

        // TAB scope: context-specific signal, not shared
        $namespace = $this->context->getNamespace();
        $signalId = $namespace
            ? $namespace . '.' . $baseName
            : $baseName . '_' . $this->context->getId();
        $signalId = preg_replace('/[^a-zA-Z0-9_]/', '_', $signalId);

        // Check if signal already exists
        if (isset($this->signals[$signalId])) {
            $this->signals[$signalId]->setValue($initialValue);

            return $this->signals[$signalId];
        }

        $signal = new Signal($signalId, $initialValue);
        $this->signals[$signalId] = $signal;

        return $signal;
    }

    /**
     * Get a signal by name.
     *
     * @param string $name Signal name (without namespace prefix)
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $name): ?Signal {
        $namespace = $this->context->getNamespace();
        $signalId = $namespace
            ? $namespace . '.' . $name
            : $name . '_' . $this->context->getId();
        $signalId = preg_replace('/[^a-zA-Z0-9_]/', '_', $signalId);

        return $this->signals[$signalId] ?? null;
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
     * Inject signals from the client.
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        // Convert nested structure back to flat
        $flat = $this->nestedToFlat($signalsData);

        foreach ($flat as $signalId => $value) {
            // First check TAB-scoped signals (context-specific)
            if (isset($this->signals[$signalId])) {
                $this->signals[$signalId]->setValue($value, false);

                continue;
            }

            // Then check scoped signals (shared across contexts in each scope)
            foreach ($this->context->getScopes() as $scope) {
                $scopedSignals = $this->app->getScopedSignals($scope);
                if (isset($scopedSignals[$signalId])) {
                    $scopedSignals[$signalId]->setValue($value, false);

                    break; // Found and updated, stop searching
                }
            }
        }
    }

    /**
     * Clear all signals.
     */
    public function clearSignals(): void {
        $this->signals = [];
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
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

            if (\is_array($value) && !$this->isAssocArray($value)) {
                // It's a regular array value, not an object
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
