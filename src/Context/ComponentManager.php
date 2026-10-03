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

    /** @var null|\WeakReference<Context> the page a component belongs to, null on a page */
    private ?\WeakReference $parentPage = null;

    /** @var array<int, array<string, string>> per collecting coroutine: the HTML of each component it rendered, by component ID */
    private array $rendered = [];

    /**
     * @param \WeakReference<Context> $context weak, so a destroyed context leaves no cycle for PHP's collector
     */
    public function __construct(
        private \WeakReference $context,
        private Via $app,
    ) {}

    /**
     * Set parent page context (for components).
     */
    public function setParentPageContext(Context $parent): void {
        $this->parentPage = \WeakReference::create($parent);
    }

    /**
     * Get parent page context.
     */
    public function getParentPageContext(): ?Context {
        return $this->parentPage?->get();
    }

    /**
     * Check if this is a component context.
     */
    public function isComponent(): bool {
        return $this->parentPage !== null;
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
        $context = $this->context->get() ?? throw new \LogicException('Component of a freed context');

        // A named component gets the same ID every time its page is built, so a revived page's
        // patches find the wrappers the browser still has.
        if ($namespace !== null) {
            // Its signal ids and action URLs carry only the namespace, so a second one would share them.
            $page = $this->getParentPageContext() ?? $context;
            $taken = self::hasNamespace($page, $namespace)
                || ($context !== $page && ($context->getNamespace() === $namespace || self::hasNamespace($context, $namespace)));
            if ($taken) {
                throw new \InvalidArgumentException(
                    "A component named '{$namespace}' is already on this page, and both would share its signals and actions. "
                    . "Give each component() its own namespace, such as '{$namespace}-' . \$key in a loop."
                );
            }
            $base = $context->getId() . '/_component/' . mb_substr(md5($namespace), 0, 16);
            $componentId = $base;
            for ($n = 2; isset($this->componentRegistry[$componentId]); ++$n) {
                $componentId = $base . '-' . $n;
            }
        } else {
            $componentId = $context->getId() . '/_component/' . IdGenerator::generate();
        }
        $componentNamespace = $namespace ?? 'c' . mb_substr(md5($componentId), 0, 8);
        $componentContext = new Context($componentId, $context->getRoute(), $this->app, $componentNamespace, $context->getSessionId());

        // A nested component's patches and request go to the page too.
        $componentContext->getComponentManager()->setParentPageContext($this->getParentPageContext() ?? $context);

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

    /**
     * Whether a component under $context, at any depth, has $namespace.
     */
    private static function hasNamespace(Context $context, string $namespace): bool {
        foreach ($context->getComponentRegistry() as $component) {
            if ($component->getNamespace() === $namespace || self::hasNamespace($component, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
