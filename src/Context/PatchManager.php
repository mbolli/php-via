<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Coroutine\Channel;

/**
 * PatchManager - Manages patch queue and signal syncing.
 *
 * Handles:
 * - Patch queue management
 * - Signal syncing
 * - View syncing
 * - Script execution
 * - Signal nesting/flattening
 */
class PatchManager {
    private Channel $patchChannel;

    public function __construct(
        private Context $context,
        private Via $app,
        private SignalFactory $signalFactory,
        private ComponentManager $componentManager,
    ) {
        $this->patchChannel = new Channel(5);
    }

    /**
     * Queue a patch for transmission to the client.
     *
     * @param array<string, mixed> $patch
     */
    public function queuePatch(array $patch): void {
        $channel = $this->getPatchChannel();

        // If channel is full, drop oldest patches to make room for new updates
        while ($channel->isFull()) {
            $dropped = $channel->pop(0);
            if ($dropped !== false) {
                $this->app->log('debug', "Dropped old patch for context {$this->context->getId()} - channel full");
            } else {
                break;
            }
        }

        $channel->push($patch);
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
     * Sync current view and signals to the browser.
     */
    public function sync(): void {
        // Skip sync if view is not defined (e.g., during broadcast before client connects)
        if (!$this->context->hasView()) {
            // Still sync signals even without a view
            $this->app->log('debug', "Context {$this->context->getId()} has no view, syncing signals only");
            $this->syncSignals();

            return;
        }

        // Sync view with proper selector for components
        $viewHtml = $this->context->renderView(isUpdate: true);

        if (!empty(trim($viewHtml))) {
            if ($this->componentManager->isComponent()) {
                // Create valid CSS ID by replacing slashes and prefixing with 'c-'
                $cssId = 'c-' . str_replace(['/', '_'], '-', $this->context->getId());
                $wrappedHtml = '<div id="' . $cssId . '">' . $viewHtml . '</div>';
                $this->queuePatch([
                    'type' => 'elements',
                    'content' => $wrappedHtml,
                    'selector' => '#' . $cssId,
                ]);
            } else {
                // For pages, update entire content
                $this->queuePatch([
                    'type' => 'elements',
                    'content' => $viewHtml,
                ]);
            }
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
            $this->queuePatch([
                'type' => 'signals',
                'content' => $updatedSignals,
            ]);
        }

        // Also sync scoped signals for all scopes this context belongs to
        $this->syncScopedSignals();
    }

    /**
     * Execute JavaScript on the client.
     */
    public function execScript(string $script): void {
        if (empty($script)) {
            return;
        }

        $this->queuePatch([
            'type' => 'script',
            'content' => $script,
        ]);
    }

    /**
     * Close the patch channel.
     */
    public function closePatchChannel(): void {
        $this->patchChannel->close();
    }

    /**
     * Recreate the patch channel (needed for SSE reconnections in new coroutines).
     */
    public function recreatePatchChannel(): void {
        try {
            $this->patchChannel->close();
        } catch (\Throwable $e) {
            // Channel might already be closed, ignore
        }

        // Create new channel for the current coroutine
        $this->patchChannel = new Channel(5);
        $this->app->log('debug', "Recreated patch channel for context {$this->context->getId()}");
    }

    /**
     * Sync scoped signals for all scopes this context belongs to.
     */
    private function syncScopedSignals(): void {
        $flat = [];

        foreach ($this->context->getScopes() as $scope) {
            // Skip TAB scope - already handled by prepareSignalsForPatch
            if ($scope === Scope::TAB) {
                continue;
            }

            $scopedSignals = $this->app->getScopedSignals($scope);
            foreach ($scopedSignals as $id => $signal) {
                // Always sync scoped signals during broadcast (don't check hasChanged)
                // because multiple contexts need to receive the same value
                $flat[$id] = $signal->getValue();
            }
        }

        if (!empty($flat)) {
            $this->queuePatch([
                'type' => 'signals',
                'content' => $this->flatToNested($flat),
            ]);
        }
    }

    /**
     * Prepare signals for patching.
     *
     * @return array<string, mixed> Nested structure of changed signals
     */
    private function prepareSignalsForPatch(): array {
        // Components should use parent's signals
        $signalsToCheck = $this->componentManager->isComponent()
            ? $this->componentManager->getParentPageContext()->getSignalFactory()->getTabSignals()
            : $this->signalFactory->getTabSignals();

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
     * Get the patch channel (for components, use parent's channel).
     */
    private function getPatchChannel(): Channel {
        if ($this->componentManager->isComponent()) {
            return $this->componentManager->getParentPageContext()->getPatchManager()->patchChannel;
        }

        return $this->patchChannel;
    }
}
