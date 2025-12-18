<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

/**
 * Manages scoped actions (actions shared across contexts).
 *
 * Handles registration, retrieval, and cleanup of actions
 * that are shared within a specific scope.
 */
class ActionRegistry {
    /** @var array<string, array<string, callable>> Scoped actions: scope => [actionId => callable] */
    private array $actions = [];

    /**
     * Register an action in a scope.
     *
     * @param string   $scope    Scope identifier
     * @param string   $actionId Action ID
     * @param callable $action   Action callable
     */
    public function registerAction(string $scope, string $actionId, callable $action): void {
        if (!isset($this->actions[$scope])) {
            $this->actions[$scope] = [];
        }
        $this->actions[$scope][$actionId] = $action;
    }

    /**
     * Get an action by scope and ID.
     *
     * @param string $scope    Scope identifier
     * @param string $actionId Action ID
     *
     * @return null|callable The action if found, null otherwise
     */
    public function getAction(string $scope, string $actionId): ?callable {
        return $this->actions[$scope][$actionId] ?? null;
    }

    /**
     * Get all actions for a scope.
     *
     * @param string $scope Scope identifier
     *
     * @return array<string, callable>
     */
    public function getActions(string $scope): array {
        return $this->actions[$scope] ?? [];
    }

    /**
     * Remove all actions from a scope.
     *
     * @param string $scope Scope identifier
     *
     * @return bool True if actions were removed, false if scope didn't exist
     */
    public function clearScope(string $scope): bool {
        if (isset($this->actions[$scope])) {
            unset($this->actions[$scope]);

            return true;
        }

        return false;
    }

    /**
     * Check if a scope has any actions.
     *
     * @param string $scope Scope identifier
     */
    public function hasActions(string $scope): bool {
        return isset($this->actions[$scope]) && !empty($this->actions[$scope]);
    }

    /**
     * Get count of actions in a scope.
     *
     * @param string $scope Scope identifier
     */
    public function getActionCount(string $scope): int {
        return \count($this->actions[$scope] ?? []);
    }

    /**
     * Get all scopes that have actions.
     *
     * @return array<string>
     */
    public function getScopes(): array {
        return array_keys($this->actions);
    }
}
