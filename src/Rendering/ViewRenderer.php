<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\Stats;
use Mbolli\PhpVia\Tracing\Tracer;
use OpenSwoole\Coroutine;
use Twig\Environment;

/**
 * Handles view rendering with scope-based caching.
 *
 * Manages Twig template rendering and caching strategies
 * based on context scope (TAB, ROUTE, SESSION, GLOBAL, custom).
 */
class ViewRenderer {
    /** @var array<string, true> Routes already warned about a full document asking to share its render */
    private array $documentShareWarned = [];

    /**
     * Per coroutine, the open fan-outs, innermost last: for each view, the hashes of its update
     * renders, how many there were and one of its contexts.
     *
     * @var array<int, list<array<string, array{hashes: array<string, true>, renders: int, context: Context}>>>
     */
    private array $fanOuts = [];

    /** @var array<string, true> Views already given the identical-render hint */
    private array $identicalHinted = [];

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
        // Only update renders are shared: an initial page load carries the context's own id.
        if ($isUpdate && $scope !== Scope::TAB && $context->shouldShareRender()) {
            $view = ViewCache::viewKey($context->getRoute(), $context->getNamespace());
            $cached = $this->cache->get($scope, true, $view);
            if ($cached !== null) {
                $this->logger->debug("Using shared update render for scope: {$scope}", $context);
                $this->recordCacheHit($scope, $context);

                return $cached;
            }

            $this->logger->debug("Rendering shared update for scope: {$scope}", $context);

            $generation = $this->cache->generation($scope);
            $result = $this->renderTraced($viewFn, $isUpdate, $context, $scope, false);

            // Not stored when a broadcast invalidated the scope while the view rendered: the render saw the older state.
            if (!$this->isSharedDocument($result, $context)) {
                $this->cache->setIfCurrent($scope, $result, true, $generation, $view);
            }

            return $result;
        }

        $this->logger->debug('Rendering ' . ($isUpdate ? 'update' : 'initial') . " view for {$route}", $context);
        $result = $this->renderTraced($viewFn, $isUpdate, $context, $scope, false);
        if ($isUpdate && $this->fanOuts !== []) {
            $this->noteFanOutRender($result, $scope, $context);
        }

        return $result;
    }

    /**
     * Open a fan-out in this coroutine. Until endFanOut(), update renders that are not shared are
     * compared per view, for the dev-mode hint that a view could share its render.
     *
     * @internal called by Via around a broadcast fan-out in dev mode
     */
    public function beginFanOut(): void {
        $this->fanOuts[Coroutine::getCid()][] = [];
    }

    /**
     * Close the innermost fan-out of this coroutine and hint, once per view, when every context of
     * a view rendered the same HTML in it.
     *
     * @internal
     */
    public function endFanOut(string $scope): void {
        $cid = Coroutine::getCid();
        $views = array_pop($this->fanOuts[$cid]) ?? [];
        if ($this->fanOuts[$cid] === []) {
            unset($this->fanOuts[$cid]);
        }

        foreach ($views as $view) {
            $context = $view['context'];
            $tabPrimary = $context->getPrimaryScope() === Scope::TAB;
            $key = ViewCache::viewKey($context->getRoute(), $context->getNamespace()) . ($tabPrimary ? "\0tab" : '');
            if ($view['renders'] < 2 || \count($view['hashes']) !== 1 || isset($this->identicalHinted[$key])) {
                continue;
            }
            $this->identicalHinted[$key] = true;

            $namespace = $context->getNamespace();
            $this->logger->info(\sprintf(
                '%d tabs of %s rendered identical HTML in one broadcast of %s. If this view is the same for every tab, %s so it renders once per broadcast.',
                $view['renders'],
                $context->getRoute() . ($namespace !== null ? " (component {$namespace})" : ''),
                $scope,
                $tabPrimary ? 'set its shared scope with $c->scope(...) and pass shareRender: true to view()' : 'pass shareRender: true to view()',
            ), $context);
        }
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

    private function noteFanOutRender(string $html, string $scope, Context $context): void {
        $cid = Coroutine::getCid();
        $depth = \count($this->fanOuts[$cid] ?? []) - 1;
        // Empty updates (a static page) and full documents (per-tab ids) say nothing about sharing.
        if ($depth < 0 || trim($html) === '' || stripos($html, '<html') !== false) {
            return;
        }

        $key = $scope . "\0" . ViewCache::viewKey($context->getRoute(), $context->getNamespace());
        $view = $this->fanOuts[$cid][$depth][$key] ?? ['hashes' => [], 'renders' => 0, 'context' => $context];
        $view['hashes'][hash('xxh128', $html)] = true;
        ++$view['renders'];
        $this->fanOuts[$cid][$depth][$key] = $view;
    }

    /**
     * Whether a shared update render is a full document, which carries the context's own id
     * (via_ctx, the beacon) and so is never shared. Warns once per route in dev mode.
     */
    private function isSharedDocument(string $html, Context $context): bool {
        if (stripos($html, '<html') === false) {
            return false;
        }

        $route = $context->getRoute();
        if ($context->getConfig()->isDevMode() && !isset($this->documentShareWarned[$route])) {
            $this->documentShareWarned[$route] = true;
            $this->logger->warn("The view of {$route} renders a full document, which holds this tab's context id, so shareRender: true is ignored and every tab renders its own update. Pass block: to render updates without the document, or drop shareRender.", $context);
        }

        return true;
    }
}
