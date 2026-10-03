<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\Stats;
use Mbolli\PhpVia\Tracing\Tracer;
use Twig\Environment;

/**
 * Handles view rendering with scope-based caching.
 *
 * Manages Twig template rendering and caching strategies
 * based on context scope (TAB, ROUTE, SESSION, GLOBAL, custom).
 */
class ViewRenderer {
    /** @var array<string, true> Routes already warned about a silently-disabled update cache */
    private array $multiScopeWarned = [];

    public function __construct(
        private Environment $twig,
        private ViewCache $cache,
        private Stats $stats,
        private Logger $logger
    ) {}

    /**
     * Render a view function with scope-based caching.
     *
     * @param callable    $viewFn   View function to execute
     * @param bool        $isUpdate Whether this is an update render
     * @param string      $scope    Primary scope for caching
     * @param Context     $context  Context for logging and settings
     * @param null|string $route    Route for logging
     *
     * @return string Rendered HTML
     */
    public function renderView(
        callable $viewFn,
        bool $isUpdate,
        string $scope,
        Context $context,
        ?string $route = null
    ): string {
        // Check if this scope supports caching (non-TAB scopes)
        // Only cache UPDATE renders, not initial page loads (which contain unique context IDs)
        $shouldCache = $scope !== Scope::TAB && $isUpdate;

        if ($shouldCache) {
            $view = ViewCache::viewKey($context->getRoute(), $context->getNamespace());

            // If context allows update caching (default true), use cache for performance
            // This prevents rendering once per client (e.g., game of life)
            if ($context->shouldCacheUpdates()) {
                $cached = $this->cache->get($scope, true, $view);
                if ($cached !== null) {
                    $this->logger->debug("Using cached update view for scope: {$scope}", $context);
                    $this->recordCacheHit($scope, $context);

                    return $cached;
                }
            }

            // Render fresh (either no cache, or cacheUpdates=false)
            $this->logger->debug("Rendering update view for scope: {$scope} (no cache)", $context);

            $generation = $this->cache->generation($scope);
            $result = $this->renderTraced($viewFn, $isUpdate, $context, $scope, false);

            // Cache the result if updates are cacheable, unless a broadcast invalidated the scope while
            // the view rendered: the render saw the older state.
            if ($context->shouldCacheUpdates()) {
                $this->cache->setIfCurrent($scope, $result, true, $generation, $view);
            }

            return $result;
        }

        // No caching for: TAB scope or initial page loads
        // Initial page loads contain unique context IDs that must not be cached
        if ($isUpdate && $scope === Scope::TAB) {
            $this->warnSilentCacheOptOut($context, $route);
        }

        $logContext = $scope === Scope::TAB ? 'TAB-scoped' : 'initial page load';
        $this->logger->debug("Rendering {$logContext} view for {$route} (no cache)", $context);

        return $this->renderTraced($viewFn, $isUpdate, $context, $scope, false);
    }

    /**
     * Render a Twig template.
     *
     * @param string               $template Template name
     * @param array<string, mixed> $data     Data to pass to template
     * @param null|string          $block    Optional block name to render
     *
     * @return string Rendered HTML
     */
    public function renderTemplate(string $template, array $data = [], ?string $block = null): string {
        if ($block !== null) {
            return $this->twig->load($template)->renderBlock($block, $data);
        }

        return $this->twig->render($template, $data);
    }

    /**
     * Render a Twig template from string.
     *
     * @param string               $template Template string
     * @param array<string, mixed> $data     Data to pass to template
     *
     * @return string Rendered HTML
     */
    public function renderString(string $template, array $data = []): string {
        return $this->twig->createTemplate($template)->render($data);
    }

    /**
     * Get the Twig environment.
     */
    public function getTwig(): Environment {
        return $this->twig;
    }

    /**
     * Invoke a view function, tracking render time and (when tracing is on)
     * recording a `render.regions` span. Zero-overhead when tracing is off.
     */
    private function renderTraced(callable $viewFn, bool $isUpdate, Context $context, string $scope, bool $cacheHit): string {
        $run = function () use ($viewFn, $isUpdate, $context): string {
            $startTime = microtime(true);
            $result = $viewFn($isUpdate, $context->getConfig()->getBasePath());
            $this->stats->trackRender(microtime(true) - $startTime);

            return $result;
        };

        $tracer = Tracer::current();
        if ($tracer === null) {
            return $run();
        }

        $isComponent = $context->getComponentManager()->isComponent();
        $attributes = [
            'render.scope' => $scope,
            'render.update' => $isUpdate,
            'cache.hit' => $cacheHit,
        ];
        if ($isComponent) {
            $attributes['component'] = $context->getNamespace() ?? $context->getId();
        }

        // Distinct span name for components so they stand out in the waterfall.
        return $tracer->span($isComponent ? 'render.component' : 'render.regions', $run, $attributes);
    }

    /**
     * Record a zero-duration render span flagged as a cache hit so the waterfall
     * shows that a render was served from the view cache.
     */
    private function recordCacheHit(string $scope, Context $context): void {
        $tracer = Tracer::current();
        if ($tracer === null) {
            return;
        }

        $isComponent = $context->getComponentManager()->isComponent();
        $attributes = ['render.scope' => $scope, 'cache.hit' => true];
        if ($isComponent) {
            $attributes['component'] = $context->getNamespace() ?? $context->getId();
        }

        $tracer->span($isComponent ? 'render.component' : 'render.regions', static fn () => null, $attributes, 'cache');
    }

    /**
     * Warn once per route when a shared-scope context looks like it could use the update cache.
     *
     * `addScope()` (and the Composition API's scoped-signal registration) subscribes a context to a
     * broadcast channel without touching the primary scope, and the update cache is keyed on the
     * primary scope alone. A TAB-primary context therefore opts out of caching entirely, and every
     * client in the shared scope re-renders on each broadcast, with nothing in the code to show it.
     *
     * For almost every caller that is the CORRECT outcome: their views embed per-client data, so a
     * shared cache entry would serve one client's HTML to another (see `b0b8dda`). Warning on those
     * would be pure noise. The narrow case worth reporting is a context that declares no TAB-scoped
     * signals at all, where promoting the shared scope is likely an N-to-1 render win. `hasSignals()`
     * is a heuristic, not a proof (the view may still read per-context state), so the message asks
     * for a decision rather than asserting one.
     */
    private function warnSilentCacheOptOut(Context $context, ?string $route): void {
        // Only ambiguous while the author still believes the view is cacheable.
        if (!$context->shouldCacheUpdates()) {
            return;
        }

        // A declared TAB signal is near-proof the render differs per client. Leave it alone.
        if ($context->getSignalFactory()->hasSignals()) {
            return;
        }

        $shared = array_values(array_filter($context->getScopes(), static fn (string $s): bool => $s !== Scope::TAB));
        if ($shared === []) {
            return;
        }

        $key = ($route ?? '?') . '|' . implode(',', $shared);
        if (isset($this->multiScopeWarned[$key])) {
            return;
        }
        $this->multiScopeWarned[$key] = true;

        $this->logger->warn(\sprintf(
            'Update cache is disabled for %s: this context broadcasts to %s but its primary scope is still TAB, so every '
            . 'client in that scope re-renders on each broadcast. It declares no TAB-scoped signals, so promoting the '
            . 'shared scope with scope() would likely render once for all of them. Do that only if the view is identical '
            . 'for every client; otherwise declare cacheUpdates: false to record that the per-client render is intended.',
            $route ?? 'this route',
            implode(', ', $shared)
        ), $context);
    }
}
