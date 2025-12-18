<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * Manages view caching by scope.
 *
 * Stores and retrieves rendered HTML content for non-TAB scopes
 * to avoid re-rendering identical views for multiple clients.
 */
class ViewCache {
    /** @var array<string, string> Cached HTML by scope */
    private array $cache = [];

    /** @var array<string, bool> Tracks if scope is currently rendering (prevents race condition) */
    private array $rendering = [];

    /**
     * Get cached view for a scope.
     *
     * @param string $scope Scope identifier
     *
     * @return null|string Cached HTML or null if not found
     */
    public function get(string $scope): ?string {
        return $this->cache[$scope] ?? null;
    }

    /**
     * Cache rendered view HTML for a scope.
     *
     * @param string $scope Scope identifier
     * @param string $html  Rendered HTML
     */
    public function set(string $scope, string $html): void {
        $this->cache[$scope] = $html;
    }

    /**
     * Invalidate cache for a scope.
     *
     * @param string $scope Scope identifier
     */
    public function invalidate(string $scope): void {
        unset($this->cache[$scope]);
    }

    /**
     * Clear all cached views.
     */
    public function clear(): void {
        $this->cache = [];
    }

    /**
     * Check if a scope is currently rendering.
     *
     * @param string $scope Scope identifier
     */
    public function isRendering(string $scope): bool {
        return $this->rendering[$scope] ?? false;
    }

    /**
     * Set rendering status for a scope.
     *
     * @param string $scope  Scope identifier
     * @param bool   $status Rendering status
     */
    public function setRendering(string $scope, bool $status): void {
        if ($status) {
            $this->rendering[$scope] = true;
        } else {
            unset($this->rendering[$scope]);
        }
    }

    /**
     * Get all cached scopes (alias for getScopes).
     *
     * @return array<string>
     */
    public function getKeys(): array {
        return array_keys($this->cache);
    }

    /**
     * Get all cached scopes.
     *
     * @return array<string>
     */
    public function getScopes(): array {
        return array_keys($this->cache);
    }

    /**
     * Get cache statistics.
     *
     * @return array{count: int, scopes: array<string>}
     */
    public function getStats(): array {
        return [
            'count' => \count($this->cache),
            'scopes' => array_keys($this->cache),
        ];
    }
}
