<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Support\IdGenerator;
use Mbolli\PhpVia\Via;

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
        $componentId = $this->context->getId() . '/_component/' . IdGenerator::generate();
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

        return function () use ($componentContext) {
            $html = $componentContext->renderView();
            // Create valid CSS ID by replacing slashes and prefixing with 'c-'
            $cssId = 'c-' . str_replace(['/', '_'], '-', $componentContext->getId());

            return '<div id="' . $cssId . '">' . $html . '</div>';
        };
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
