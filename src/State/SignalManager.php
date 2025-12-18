<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Signal;

/**
 * Manages scoped signals (signals shared across contexts).
 *
 * Handles registration, retrieval, and cleanup of signals
 * that are shared within a specific scope.
 */
class SignalManager {
    /** @var array<string, array<string, Signal>> Scoped signals: scope => [signalId => Signal] */
    private array $signals = [];

    /**
     * Register a signal in a scope.
     *
     * @param string $scope  Scope identifier
     * @param Signal $signal Signal to register
     */
    public function registerSignal(string $scope, Signal $signal): void {
        if (!isset($this->signals[$scope])) {
            $this->signals[$scope] = [];
        }
        $this->signals[$scope][$signal->id()] = $signal;
    }

    /**
     * Get a signal by scope and ID.
     *
     * @param string $scope    Scope identifier
     * @param string $signalId Signal ID
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $scope, string $signalId): ?Signal {
        return $this->signals[$scope][$signalId] ?? null;
    }

    /**
     * Get all signals for a scope.
     *
     * @param string $scope Scope identifier
     *
     * @return array<string, Signal>
     */
    public function getSignals(string $scope): array {
        return $this->signals[$scope] ?? [];
    }

    /**
     * Remove all signals from a scope.
     *
     * @param string $scope Scope identifier
     *
     * @return bool True if signals were removed, false if scope didn't exist
     */
    public function clearScope(string $scope): bool {
        if (isset($this->signals[$scope])) {
            unset($this->signals[$scope]);

            return true;
        }

        return false;
    }

    /**
     * Check if a scope has any signals.
     *
     * @param string $scope Scope identifier
     */
    public function hasSignals(string $scope): bool {
        return isset($this->signals[$scope]) && !empty($this->signals[$scope]);
    }

    /**
     * Get count of signals in a scope.
     *
     * @param string $scope Scope identifier
     */
    public function getSignalCount(string $scope): int {
        return \count($this->signals[$scope] ?? []);
    }

    /**
     * Get all scopes that have signals.
     *
     * @return array<string>
     */
    public function getScopes(): array {
        return array_keys($this->signals);
    }
}
