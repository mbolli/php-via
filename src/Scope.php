<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

/**
 * Defines the scope of state and rendering for a page or context.
 *
 * Scope determines:
 * - Which contexts receive broadcast updates
 * - How view caching works
 * - How state is shared
 *
 * Scopes can be:
 * - Built-in constants (TAB, ROUTE, SESSION, GLOBAL)
 * - Custom strings (e.g., "room:123", "user:456", "topic:stock:AAPL")
 */
class Scope {
    /**
     * Built-in scope: Tab-scoped (default).
     * Each browser tab/context has isolated state.
     */
    public const TAB = 'tab';

    /**
     * Built-in scope: Route-scoped.
     * State is shared across all users on the same route.
     * View is cached and reused.
     */
    public const ROUTE = 'route';

    /**
     * Built-in scope: Session-scoped.
     * State is shared across all tabs in the same browser session.
     */
    public const SESSION = 'session';

    /**
     * Built-in scope: Global-scoped.
     * State is shared across ALL routes and users.
     */
    public const GLOBAL = 'global';

    /**
     * Parse a scope string into its components.
     *
     * Examples:
     * - "tab" => ["tab"]
     * - "route:/users" => ["route", "/users"]
     * - "room:lobby" => ["room", "lobby"]
     * - "user:123:notifications" => ["user", "123", "notifications"]
     *
     * @return array<string>
     */
    public static function parse(string $scope): array {
        return explode(':', $scope);
    }

    /**
     * Build a scope string from components.
     *
     * @param string ...$parts Scope parts (e.g., "room", "lobby")
     */
    public static function build(string ...$parts): string {
        return implode(':', $parts);
    }

    /**
     * Check if a scope is a built-in scope.
     */
    public static function isBuiltIn(string $scope): bool {
        return \in_array($scope, [self::TAB, self::ROUTE, self::SESSION, self::GLOBAL], true);
    }

    /**
     * Check if a scope is route-based (either ROUTE or includes route).
     */
    public static function isRouteBased(string $scope, ?string $route = null): bool {
        if ($scope === self::ROUTE) {
            return true;
        }

        $parts = self::parse($scope);
        if ($parts[0] === 'route' && isset($parts[1])) {
            return $route === null || $parts[1] === $route;
        }

        return false;
    }

    /**
     * Get the canonical route scope for a route.
     */
    public static function routeScope(string $route): string {
        return self::build('route', $route);
    }

    /**
     * Check if a scope matches a pattern.
     *
     * Patterns support wildcards:
     * - "room:*" matches any room
     * - "user:123:*" matches all scopes for user 123
     *
     * @param string $scope   The scope to check
     * @param string $pattern The pattern to match against
     */
    public static function matches(string $scope, string $pattern): bool {
        // Exact match
        if ($scope === $pattern) {
            return true;
        }

        // Wildcard pattern
        if (str_contains($pattern, '*')) {
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';

            return (bool) preg_match($regex, $scope);
        }

        return false;
    }
}
