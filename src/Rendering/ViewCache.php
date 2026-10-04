<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * Manages view caching by scope.
 *
 * Stores and retrieves rendered HTML content for non-TAB scopes
 * to avoid re-rendering identical views for multiple clients.
 *
 * Entries are keyed by scope and by view (see viewKey()): two views that share a scope
 * keep separate entries, and invalidating the scope drops all of them.
 */
class ViewCache {
    private const int MAX_GENERATIONS = 10_000;

    /** @var array<string, array<string, string>> Cached HTML by scope, then by view and render kind */
    private array $cache = [];

    /** @var array<string, int> Bumped by invalidate(), so a render that started before it is not stored */
    private array $generations = [];

    /** Bumped by clear(), for the same reason */
    private int $epoch = 0;

    /** @var array<string, int> Shared renders running per scope, between beginRender() and endRender() */
    private array $rendering = [];

    /**
     * The view part of a cache key: the route pattern a context was created for and, for a
     * component, its namespace.
     */
    public static function viewKey(string $route, ?string $namespace): string {
        return $namespace === null ? $route : $route . "\0" . $namespace;
    }

    /**
     * Get cached view for a scope.
     *
     * @param string $scope    Scope identifier
     * @param bool   $isUpdate Whether this is an update render
     * @param string $view     View identifier, see viewKey()
     *
     * @return null|string Cached HTML or null if not found
     */
    public function get(string $scope, bool $isUpdate = false, string $view = ''): ?string {
        return $this->cache[$scope][$this->getCacheKey($view, $isUpdate)] ?? null;
    }

    /**
     * Cache rendered view HTML for a scope.
     *
     * @param string $scope    Scope identifier
     * @param string $html     Rendered HTML
     * @param bool   $isUpdate Whether this is an update render
     * @param string $view     View identifier, see viewKey()
     */
    public function set(string $scope, string $html, bool $isUpdate = false, string $view = ''): void {
        $this->cache[$scope][$this->getCacheKey($view, $isUpdate)] = $html;
    }

    /**
     * Invalidate every cached view of a scope, initial and update renders alike.
     *
     * @param string $scope Scope identifier
     */
    public function invalidate(string $scope): void {
        unset($this->cache[$scope]);
        $this->generations[$scope] = ($this->generations[$scope] ?? 0) + 1;

        // Per-entity scopes (one per room or visitor) would grow the map for the life of the worker.
        // A new epoch voids every token handed out, which costs at most one extra render each.
        if (\count($this->generations) > self::MAX_GENERATIONS) {
            $this->generations = [];
            ++$this->epoch;
        }
    }

    /**
     * Clear all cached views.
     */
    public function clear(): void {
        $this->cache = [];
        $this->generations = [];
        ++$this->epoch;
    }

    /**
     * A token for the scope's current generation. Take it before rendering and store the render
     * with setIfCurrent(): a render can suspend, and an invalidation meanwhile makes it stale.
     */
    public function generation(string $scope): string {
        return $this->epoch . ':' . ($this->generations[$scope] ?? 0);
    }

    /**
     * generation() for a shared render that starts now; endRender() must follow, also when it throws.
     */
    public function beginRender(string $scope): string {
        $this->rendering[$scope] = ($this->rendering[$scope] ?? 0) + 1;

        return $this->generation($scope);
    }

    public function endRender(string $scope): void {
        if (--$this->rendering[$scope] <= 0) {
            unset($this->rendering[$scope]);
        }
    }

    /**
     * Whether nothing is cached and no shared render runs, so no invalidation can change anything.
     */
    public function isIdle(): bool {
        return $this->cache === [] && $this->rendering === [];
    }

    /**
     * Cache rendered view HTML unless the scope was invalidated since $generation was taken.
     */
    public function setIfCurrent(string $scope, string $html, bool $isUpdate, string $generation, string $view = ''): void {
        if ($this->generation($scope) === $generation) {
            $this->set($scope, $html, $isUpdate, $view);
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
            'count' => array_sum(array_map(\count(...), $this->cache)),
            'scopes' => array_keys($this->cache),
        ];
    }

    private function getCacheKey(string $view, bool $isUpdate): string {
        return $isUpdate ? "{$view}:update" : "{$view}:initial";
    }
}
