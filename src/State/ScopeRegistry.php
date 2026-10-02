<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/**
 * Manages context registration and lookup by scope.
 *
 * Tracks which contexts belong to which scopes and handles
 * scope cleanup when contexts disconnect.
 */
class ScopeRegistry {
    /** @var array<string, array<string, Context>> Scope registry: scope => [contextId => Context] */
    private array $registry = [];

    /**
     * Where each context is registered, so teardown finds every entry. A context's own scope list
     * can miss some: scope() replaces the list, and the TAB entry is added outside it.
     *
     * @var array<string, array<string, true>> contextId => [scope => true]
     */
    private array $scopesByContext = [];

    /**
     * Register a context under a specific scope.
     *
     * @param Context $context Context to register
     * @param string  $scope   Scope identifier
     */
    public function registerContext(Context $context, string $scope): void {
        if (!isset($this->registry[$scope])) {
            $this->registry[$scope] = [];
        }
        $this->registry[$scope][$context->getId()] = $context;
        $this->scopesByContext[$context->getId()][$scope] = true;
    }

    /**
     * Unregister a context from a specific scope.
     *
     * @param Context $context Context to unregister
     * @param string  $scope   Scope identifier
     *
     * @return bool True if scope became empty after unregistration
     */
    public function unregisterContext(Context $context, string $scope): bool {
        $contextId = $context->getId();
        unset($this->scopesByContext[$contextId][$scope]);
        if (($this->scopesByContext[$contextId] ?? null) === []) {
            unset($this->scopesByContext[$contextId]);
        }

        if (isset($this->registry[$scope])) {
            unset($this->registry[$scope][$context->getId()]);

            // Return true if scope is now empty
            if (empty($this->registry[$scope])) {
                unset($this->registry[$scope]);

                return true;
            }
        }

        return false;
    }

    /**
     * Unregister a context from every scope it is registered in.
     *
     * @param Context $context Context to unregister
     *
     * @return array<string> List of scopes that became empty
     */
    public function unregisterContextFromAllScopes(Context $context): array {
        $emptyScopes = [];
        $scopes = array_keys($this->scopesByContext[$context->getId()] ?? []);

        foreach (array_unique([...$context->getScopes(), ...$scopes]) as $scope) {
            if ($this->unregisterContext($context, $scope)) {
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
}
