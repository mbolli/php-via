<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/**
 * Manages context registration and lookup by scope.
 *
 * Keeps two things per scope: the contexts a broadcast renders (the registry), and how many live
 * contexts use the scope (its members). A tab whose stream ends leaves the registry but stays a
 * member until it is destroyed, so its scope keeps its signals and actions for a reconnect.
 */
class ScopeRegistry {
    /** @var array<string, array<string, Context>> Scope registry: scope => [contextId => Context] */
    private array $registry = [];

    /**
     * The scopes each context object uses, true where a broadcast renders it. A context's own scope list
     * can miss some: scope() replaces the list, and the TAB entry is added outside it. Keyed by object,
     * because a revived context reuses the ID of the one being torn down.
     *
     * @var \WeakMap<Context, array<string, bool>>
     */
    private \WeakMap $scopesByContext;

    /** @var array<string, int> Live member contexts per scope */
    private array $members = [];

    public function __construct() {
        $this->scopesByContext = new \WeakMap();
    }

    /**
     * Register a context under a specific scope: broadcasts of the scope render it, and it is a member until
     * unregisterContextFromAllScopes().
     *
     * @param Context $context Context to register
     * @param string  $scope   Scope identifier
     */
    public function registerContext(Context $context, string $scope): void {
        if (!isset($this->registry[$scope])) {
            $this->registry[$scope] = [];
        }
        $this->registry[$scope][$context->getId()] = $context;
        $this->setMembership($context, $scope, true);
    }

    /**
     * Make a context a member of a scope without registering it for its broadcasts, as a scoped action
     * that is found without joining its scope does.
     */
    public function retain(Context $context, string $scope): void {
        $this->setMembership($context, $scope, $this->scopesByContext[$context][$scope] ?? false);
    }

    /**
     * Stop rendering a context on broadcasts of a scope. It stays a member, so the scope keeps its signals
     * and actions until the context is destroyed.
     *
     * @param Context $context Context to unregister
     * @param string  $scope   Scope identifier
     */
    public function unregisterContext(Context $context, string $scope): void {
        $scopes = $this->scopesByContext[$context] ?? [];
        if (($scopes[$scope] ?? false) === true) {
            $scopes[$scope] = false;
            $this->scopesByContext[$context] = $scopes;
        }

        $this->removeEntry($context, $scope);
    }

    /**
     * Unregister a context from every scope it uses, for good.
     *
     * @param Context $context Context to unregister
     *
     * @return array<string> the scopes it was the last member of
     */
    public function unregisterContextFromAllScopes(Context $context): array {
        $emptyScopes = [];
        $scopes = $this->scopesByContext[$context] ?? [];
        unset($this->scopesByContext[$context]);

        foreach (array_unique([...$context->getScopes(), ...array_keys($scopes)]) as $scope) {
            $this->removeEntry($context, $scope);
            if (!isset($scopes[$scope])) {
                continue;
            }
            if (--$this->members[$scope] <= 0) {
                unset($this->members[$scope]);
                $emptyScopes[] = $scope;
            }
        }

        return $emptyScopes;
    }

    /**
     * Get all contexts registered under a specific scope.
     *
     * @param string $scope Scope identifier
     *
     * @return array<Context>
     */
    public function getContextsByScope(string $scope): array {
        return array_values($this->registry[$scope] ?? []);
    }

    /**
     * Get contexts matching a scope pattern (supports wildcards).
     *
     * @param string $scopePattern Scope pattern (may contain wildcards)
     *
     * @return array<Context>
     */
    public function getContextsByScopePattern(string $scopePattern): array {
        if (!str_contains($scopePattern, '*')) {
            // No wildcard, direct lookup
            return $this->getContextsByScope($scopePattern);
        }

        // Wildcard pattern - match all scopes
        $matchedContexts = [];
        foreach ($this->registry as $registeredScope => $contexts) {
            if (Scope::matches($registeredScope, $scopePattern)) {
                $matchedContexts = array_merge($matchedContexts, array_values($contexts));
            }
        }

        return $matchedContexts;
    }

    /**
     * Get all registered scopes.
     *
     * @return array<string>
     */
    public function getAllScopes(): array {
        return array_keys($this->registry);
    }

    /**
     * Check if a scope exists and has contexts.
     *
     * @param string $scope Scope identifier
     */
    public function hasScope(string $scope): bool {
        return isset($this->registry[$scope]) && !empty($this->registry[$scope]);
    }

    /**
     * Get count of contexts in a scope.
     *
     * @param string $scope Scope identifier
     */
    public function getContextCount(string $scope): int {
        return \count($this->registry[$scope] ?? []);
    }

    /**
     * How many live contexts use a scope, registered for its broadcasts or not.
     */
    public function getMemberCount(string $scope): int {
        return $this->members[$scope] ?? 0;
    }

    private function setMembership(Context $context, string $scope, bool $registered): void {
        $scopes = $this->scopesByContext[$context] ?? [];
        if (!isset($scopes[$scope])) {
            $this->members[$scope] = ($this->members[$scope] ?? 0) + 1;
        }
        $scopes[$scope] = $registered;
        $this->scopesByContext[$context] = $scopes;
    }

    private function removeEntry(Context $context, string $scope): void {
        // The entry may belong to a newer context with the same ID (a revival); leave it.
        if (($this->registry[$scope][$context->getId()] ?? null) !== $context) {
            return;
        }

        unset($this->registry[$scope][$context->getId()]);
        if ($this->registry[$scope] === []) {
            unset($this->registry[$scope]);
        }
    }
}
