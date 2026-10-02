<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Support\IdGenerator;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

/**
 * ComponentManager - Manages component creation and rendering.
 *
 * Handles:
 * - Component creation
 * - Component registry
 * - Component rendering
 * - Parent-child relationships
 */
class ComponentManager {
    /** @var array<string, Context> */
    private array $componentRegistry = [];

    private ?Context $parentPageContext = null;

    /** @var array<int, array<string, string>> per collecting coroutine: the HTML of each component it rendered, by component ID */
    private array $rendered = [];

    public function __construct(
        private Context $context,
        private Via $app,
    ) {}

    /**
     * Set parent page context (for components).
     */
    public function setParentPageContext(Context $parent): void {
        $this->parentPageContext = $parent;
    }

    /**
     * Get parent page context.
     */
    public function getParentPageContext(): ?Context {
        return $this->parentPageContext;
    }

    /**
     * Check if this is a component context.
     */
    public function isComponent(): bool {
        return $this->parentPageContext !== null;
    }

    /**
     * Get all component contexts.
     *
     * @return array<string, Context>
     */
    public function getComponentRegistry(): array {
        return $this->componentRegistry;
    }

    /**
     * Get all component contexts (alias for getComponentRegistry).
     *
     * @return array<string, Context>
     */
    public function getComponents(): array {
        return $this->componentRegistry;
    }

    /**
     * Create a component (sub-context).
     *
     * @param callable    $fn        Component initialization function
     * @param null|string $namespace Optional namespace for component signals
     *
     * @return callable Returns a function that renders the component
     */
    public function createComponent(callable $fn, ?string $namespace = null): callable {
        // A named component gets the same ID every time its page is built, so a revived page's
        // patches find the wrappers the browser still has.
        if ($namespace !== null) {
            $base = $this->context->getId() . '/_component/' . mb_substr(md5($namespace), 0, 16);
            $componentId = $base;
            for ($n = 2; isset($this->componentRegistry[$componentId]); ++$n) {
                $componentId = $base . '-' . $n;
            }
        } else {
            $componentId = $this->context->getId() . '/_component/' . IdGenerator::generate();
        }
        $componentNamespace = $namespace ?? 'c' . mb_substr(md5($componentId), 0, 8);
        $componentContext = new Context($componentId, $this->context->getRoute(), $this->app, $componentNamespace);

        // Set parent context
        if ($this->isComponent()) {
            $componentContext->getComponentManager()->setParentPageContext($this->parentPageContext);
        } else {
            $componentContext->getComponentManager()->setParentPageContext($this->context);
        }

        $fn($componentContext);

        $this->componentRegistry[$componentId] = $componentContext;

        return function () use ($componentContext, $componentId): string {
            $html = $componentContext->renderView();
            // Create valid CSS ID by replacing slashes and prefixing with 'c-'
            $cssId = 'c-' . str_replace(['/', '_'], '-', $componentContext->getId());
            $wrapped = '<div id="' . $cssId . '">' . $html . '</div>';
            $cid = Coroutine::getCid();
            if (isset($this->rendered[$cid])) {
                $this->rendered[$cid][$componentId] = $wrapped;
            }

            return $wrapped;
        };
    }

    /**
     * Call $render and note the HTML of each component it renders in this coroutine.
     *
     * Kept per coroutine: two syncs of one page can render it at once.
     *
     * @param callable(): string $render
     *
     * @return array{string, array<string, string>} what $render returned, and each component's HTML by component ID
     */
    public function renderCollecting(callable $render): array {
        $cid = Coroutine::getCid();
        $this->rendered[$cid] = [];

        try {
            $html = $render();

            return [$html, $this->rendered[$cid]];
        } finally {
            unset($this->rendered[$cid]);
        }
    }

    /**
     * Register a component context.
     */
    public function registerComponent(string $componentId, Context $componentContext): void {
        $this->componentRegistry[$componentId] = $componentContext;
    }

    /**
     * Clear all component registrations.
     */
    public function clearComponents(): void {
        $this->componentRegistry = [];
    }
}
