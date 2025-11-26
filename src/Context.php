<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * Context represents a living bridge between PHP and the browser.
 *
 * It holds runtime state, defines actions, manages reactive signals, and defines UI through View.
 */
class Context {
    private string $id;
    private string $route;
    private Via $app;
    private ?\Closure $viewFn = null;
    private bool $isInitialRender = true;

    /** @var array<string, self> */
    private array $componentRegistry = [];
    private ?Context $parentPageContext = null;
    private Channel $patchChannel;

    /** @var array<string, callable> */
    private array $actionRegistry = [];

    /** @var array<string, Signal> */
    private array $signals = [];
    private ?string $namespace = null;
    
    /** @var array<callable> */
    private array $cleanupCallbacks = [];

    public function __construct(string $id, string $route, Via $app, ?string $namespace = null) {
        $this->id = $id;
        $this->route = $route;
        $this->app = $app;
        $this->namespace = $namespace;
        $this->patchChannel = new Channel(100);
    }

    /**
     * Get context ID.
     */
    public function getId(): string {
        return $this->id;
    }
    
    /**
     * Register a callback to be executed when the context is cleaned up (SSE disconnect).
     */
    public function onCleanup(callable $callback): void {
        $this->cleanupCallbacks[] = $callback;
    }
    
    /**
     * Execute cleanup callbacks and release resources.
     */
    public function cleanup(): void {
        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Silently ignore cleanup errors
            }
        }
        $this->cleanupCallbacks = [];
    }

    public function getRoute(): string {
        return $this->route;
    }

    public function getApp(): Via {
        return $this->app;
    }

    /**
     * Define the UI rendered by this context.
     *
     * @param callable|string      $view Function that returns HTML content, or Twig template name
     * @param array<string, mixed> $data Optional data for Twig templates
     */
    public function view(callable|string $view, array $data = []): void {
        if (\is_string($view)) {
            // Twig template name
            $this->viewFn = function () use ($view, $data) {
                $html = $this->app->getTwig()->render($view, $data);

                return '<div id="' . $this->id . '">' . $html . '</div>';
            };
        } elseif (\is_callable($view)) {
            // Callable function - don't wrap, let the callable handle its own structure
            $this->viewFn = $view;
        } else {
            throw new \RuntimeException('View must be a template name or callable');
        }
    }

    /**
     * Render a Twig template with context data.
     *
     * @param array<string, mixed> $data  Data to pass to the template
     * @param null|string          $block Optional block name to render only that block
     */
    public function render(string $template, array $data = [], ?string $block = null): string {
        if ($block !== null) {
            // Render only the specified block
            $twig = $this->app->getTwig();
            $twigTemplate = $twig->load($template);

            return $twigTemplate->renderBlock($block, $data);
        }

        return $this->app->getTwig()->render($template, $data);
    }

    /**
     * Render a Twig template from string.
     *
     * @param array<string, mixed> $data Data to pass to the template
     */
    public function renderString(string $template, array $data = []): string {
        $twig = $this->app->getTwig();

        return $twig->createTemplate($template)->render($data);
    }

    /**
     * Render the view.
     */
    public function renderView(): string {
        if ($this->viewFn === null) {
            throw new \RuntimeException('View not defined');
        }

        $isUpdate = !$this->isInitialRender;

        // Check if the view function accepts parameters
        $reflection = new \ReflectionFunction($this->viewFn);
        if ($reflection->getNumberOfParameters() > 0) {
            $result = ($this->viewFn)($isUpdate);
        } else {
            $result = ($this->viewFn)();
        }

        // After first render, all subsequent renders are updates
        $this->isInitialRender = false;

        return $result;
    }

    /**
     * Create a reactive signal.
     *
     * @param mixed $initialValue Initial value for the signal
     */
    /**
     * Create a reactive signal.
     *
     * @param mixed       $initialValue Initial value of the signal
     * @param null|string $name         Optional human-readable name
     */
    public function signal(mixed $initialValue, ?string $name = null): Signal {
        $baseName = $name ?? 'signal';
        $signalId = $this->namespace
            ? $this->namespace . '.' . $baseName
            : $this->generateId($baseName);

        $signal = new Signal($signalId, $initialValue);

        // Components register signals on parent page
        if ($this->isComponent()) {
            $this->parentPageContext->signals[$signalId] = $signal;
        } else {
            $this->signals[$signalId] = $signal;
        }

        return $signal;
    }

    /**
     * Create an action trigger.
     *
     * @param callable    $fn   The action function to execute
     * @param null|string $name Optional human-readable name
     */
    public function action(callable $fn, ?string $name = null): Action {
        $actionId = $this->generateId($name ?? 'action');
        $this->actionRegistry[$actionId] = $fn;

        return new Action($actionId);
    }

    /**
     * Execute a function periodically.
     *
     * @param int      $milliseconds Interval in milliseconds
     * @param callable $fn           The function to execute
     *
     * @return int Timer ID that can be used to clear the timer
     */
    public function interval(int $milliseconds, callable $fn): int {
        return Timer::tick($milliseconds, function () use ($fn): void {
            $fn();
        });
    }

    /**
     * Execute a registered action.
     */
    public function executeAction(string $actionId): void {
        // First check this context
        if (isset($this->actionRegistry[$actionId])) {
            $action = $this->actionRegistry[$actionId];
            $action();

            return;
        }

        // Check component contexts
        foreach ($this->componentRegistry as $component) {
            if ($component->hasAction($actionId)) {
                $component->executeAction($actionId);

                return;
            }
        }

        throw new \RuntimeException("Action not found: {$actionId}");
    }

    /**
     * Create a component (sub-context).
     *
     * @param callable    $fn        Component initialization function
     * @param null|string $namespace Optional namespace for component signals
     *
     * @return callable Returns a function that renders the component
     */
    public function component(callable $fn, ?string $namespace = null): callable {
        $componentId = $this->id . '/_component/' . $this->generateId();
        $componentNamespace = $namespace ?? 'c' . mb_substr(md5($componentId), 0, 8);
        $componentContext = new self($componentId, $this->route, $this->app, $componentNamespace);

        if ($this->isComponent()) {
            $componentContext->parentPageContext = $this->parentPageContext;
        } else {
            $componentContext->parentPageContext = $this;
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
     * Sync current view and signals to the browser.
     */
    public function sync(): void {
        // Sync view with proper selector for components
        $viewHtml = $this->renderView();

        if ($this->isComponent()) {
            // Create valid CSS ID by replacing slashes and prefixing with 'c-'
            $cssId = 'c-' . str_replace(['/', '_'], '-', $this->id);
            $wrappedHtml = '<div id="' . $cssId . '">' . $viewHtml . '</div>';
            $this->getPatchChannel()->push([
                'type' => 'elements',
                'content' => $wrappedHtml,
                'selector' => '#' . $cssId,
            ]);
        } else {
            // For pages, update entire content
            $this->getPatchChannel()->push([
                'type' => 'elements',
                'content' => $viewHtml,
            ]);
        }

        // Sync signals
        $this->syncSignals();
    }

    /**
     * Sync only signals to the browser.
     */
    public function syncSignals(): void {
        $updatedSignals = $this->prepareSignalsForPatch();

        if (!empty($updatedSignals)) {
            $this->getPatchChannel()->push([
                'type' => 'signals',
                'content' => $updatedSignals,
            ]);
        }
    }

    /**
     * Sync specific HTML elements to the browser.
     */
    public function syncElements(string $html): void {
        $this->getPatchChannel()->push([
            'type' => 'elements',
            'content' => $html,
        ]);
    }

    /**
     * Execute JavaScript on the client.
     */
    public function execScript(string $script): void {
        if (empty($script)) {
            return;
        }

        $this->patchChannel->push([
            'type' => 'script',
            'content' => $script,
        ]);
    }

    /**
     * Inject signals from the client.
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        // Convert nested structure back to flat
        $flat = $this->nestedToFlat($signalsData);

        foreach ($flat as $signalId => $value) {
            if (isset($this->signals[$signalId])) {
                $this->signals[$signalId]->setValue($value, false);
            }
        }
    }

    /**
     * Get next patch from the queue.
     *
     * @return null|array<string, mixed> Next patch data or null if none available
     */
    public function getPatch(): ?array {
        if ($this->patchChannel->isEmpty()) {
            return null;
        }

        return $this->patchChannel->pop(0.01);
    }

    /**
     * Prepare signals for patching.
     *
     * @return array<string, mixed> Nested structure of changed signals
     */
    public function prepareSignalsForPatch(): array {
        // Components should use parent's signals
        $signalsToCheck = $this->isComponent()
            ? $this->parentPageContext->signals
            : $this->signals;

        $flat = [];

        foreach ($signalsToCheck as $id => $signal) {
            if ($signal->hasChanged()) {
                $flat[$id] = $signal->getValue();
                $signal->markSynced();
            }
        }

        // Convert flat structure to nested object for namespaced signals
        return $this->flatToNested($flat);
    }

    public function getNamespace(): ?string {
        return $this->namespace;
    }

    /**
     * Check if this context has a specific action.
     */
    private function hasAction(string $actionId): bool {
        return isset($this->actionRegistry[$actionId]);
    }

    /**
     * Convert nested signal structure to flat
     * e.g., {"counter1": {"count": 0}} => {"counter1.count": 0}.
     *
     * @param array<int|string, mixed> $nested
     *
     * @return array<string, mixed>
     */
    private function nestedToFlat(array $nested, string $prefix = ''): array {
        $flat = [];

        foreach ($nested as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

            if (\is_array($value) && !$this->isAssocArray($value)) {
                // It's a regular array value, not an object
                $flat[$fullKey] = $value;
            } elseif (\is_array($value)) {
                // It's an object/nested structure - recurse
                $flat = array_merge($flat, $this->nestedToFlat($value, $fullKey));
            } else {
                // It's a scalar value
                $flat[$fullKey] = $value;
            }
        }

        return $flat;
    }

    /**
     * Check if array is associative (object-like).
     *
     * @param array<int|string, mixed> $arr
     */
    private function isAssocArray(array $arr): bool {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }

    /**
     * Convert flat signal structure to nested object
     * e.g., {"counter1.count": 0} => {"counter1": {"count": 0}}.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private function flatToNested(array $flat): array {
        $nested = [];

        foreach ($flat as $key => $value) {
            if (mb_strpos($key, '.') !== false) {
                // Namespaced signal - convert to nested structure
                $parts = explode('.', $key);
                $current = &$nested;

                foreach ($parts as $i => $part) {
                    if ($i === \count($parts) - 1) {
                        // Last part - set the value
                        $current[$part] = $value;
                    } else {
                        // Intermediate part - ensure object exists
                        if (!isset($current[$part]) || !\is_array($current[$part])) {
                            $current[$part] = [];
                        }
                        $current = &$current[$part];
                    }
                }
            } else {
                // Non-namespaced signal - keep flat
                $nested[$key] = $value;
            }
        }

        return $nested;
    }

    /**
     * Check if this is a component context.
     */
    private function isComponent(): bool {
        return $this->parentPageContext !== null;
    }

    /**
     * Generate random ID.
     */
    /**
     * Generate a unique ID with optional human-readable prefix.
     *
     * @param null|string $prefix Optional prefix for the ID
     */
    private function generateId(?string $prefix = null): string {
        $random = bin2hex(random_bytes(4)); // Shortened to 8 chars

        return $prefix ? "{$prefix}-{$random}" : $random;
    }

    /**
     * Get the patch channel (for components, use parent's channel).
     */
    private function getPatchChannel(): Channel {
        if ($this->isComponent()) {
            return $this->parentPageContext->patchChannel;
        }

        return $this->patchChannel;
    }
}
