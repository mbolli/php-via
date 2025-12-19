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
     * @param string $scope    Scope identifier
     * @param bool   $isUpdate Whether this is an update render
     *
     * @return null|string Cached HTML or null if not found
     */
    public function get(string $scope, bool $isUpdate = false): ?string {
        $key = $this->getCacheKey($scope, $isUpdate);

        return $this->cache[$key] ?? null;
    }

    /**
     * Cache rendered view HTML for a scope.
     *
     * @param string $scope    Scope identifier
     * @param string $html     Rendered HTML
     * @param bool   $isUpdate Whether this is an update render
     */
    public function set(string $scope, string $html, bool $isUpdate = false): void {
        $key = $this->getCacheKey($scope, $isUpdate);
        $this->cache[$key] = $html;
    }

    /**
     * Invalidate cache for a scope.
     *
     * IMPORTANT: Pass the BASE scope string (e.g., "route:/path"), NOT the cache key
     * (e.g., "route:/path:update"). This method will invalidate both :initial and :update caches.
     *
     * @param string $scope Scope identifier (without :initial or :update suffix)
     */
    public function invalidate(string $scope): void {
        unset($this->cache[$this->getCacheKey($scope, false)], $this->cache[$this->getCacheKey($scope, true)]);
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

    /**
     * Generate cache key from scope and update flag.
     *
     * @param string $scope    Scope identifier
     * @param bool   $isUpdate Whether this is an update render
     *
     * @return string Cache key
     */
    private function getCacheKey(string $scope, bool $isUpdate): string {
        return $isUpdate ? "{$scope}:update" : "{$scope}:initial";
    }
}
