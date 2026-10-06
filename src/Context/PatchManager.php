<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Support\DomId;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * PatchManager - Manages patch queue and signal syncing.
 *
 * Handles:
 * - Patch queue management
 * - Signal syncing
 * - View syncing
 * - Script execution
 * - Signal nesting/flattening
 *
 * @phpstan-type QueuedPatch array{type: string, content: mixed, selector?: string, mode?: PatchMode|ElementPatchMode, viewTransition?: string|true, confirm?: callable(): void}
 */
class PatchManager {
    private const int CHANNEL_CAPACITY = 50;

    /** How long an idle SSE loop parks when the keep-alive comment is disabled. */
    private const int IDLE_BACKSTOP_MS = 60_000;

    /** Pushed by wakeConsumers(); getPatch() turns it into null, so it never reaches a client. */
    private const string WAKE = 'via:wake';

    /** @var null|Channel|list<QueuedPatch> */
    private array|Channel|null $patchChannel = null;
    private bool $useArray = false;

    /** Via::serveInProcess() runs the app: the array queue behaves as a Channel whose consumer parks in a Fiber. */
    private bool $inProcess = false;

    /**
     * In process: the SSE loop's fiber, parked in getPatch() on an empty queue.
     *
     * @var null|\Fiber<mixed, mixed, mixed, mixed>
     */
    private ?\Fiber $parked = null;

    /** In process: closePatchChannel() ran and recreatePatchChannel() has not since, as with a closed Channel. */
    private bool $closed = false;

    /** Blocking-pop timeout in seconds: the keep-alive interval, which bounds how long an idle SSE loop parks. */
    private float $pollTimeout = 15.0;

    /** Whether the last getPatch() found the channel closed rather than merely idle. */
    private bool $channelClosed = false;

    /**
     * Per action coroutine: the TAB signals a sync queued during the action, with their write count then.
     *
     * @var array<int, \WeakMap<Signal, int>>
     */
    private array $queuedInAction = [];

    private string $contextId;

    /**
     * @param \WeakReference<Context> $context weak, so a destroyed context leaves no cycle for PHP's collector
     */
    public function __construct(
        private \WeakReference $context,
        private Via $app,
        private SignalFactory $signalFactory,
        private ComponentManager $componentManager,
    ) {
        $this->contextId = $context->get()?->getId() ?? '';
        // In test mode (no OpenSwoole server running), use array instead of Channel
        $this->inProcess = $app->isInProcess();
        $inTestMode = $this->inProcess || getenv('VIA_TEST_MODE') === '1';

        $keepAliveMs = $app->getSettings()->sseKeepAliveMs;
        $this->pollTimeout = ($keepAliveMs > 0 ? $keepAliveMs : self::IDLE_BACKSTOP_MS) / 1000;

        if ($inTestMode) {
            $this->patchChannel = [];
            $this->useArray = true;
        } else {
            $this->patchChannel = new Channel(self::CHANNEL_CAPACITY);
            $this->useArray = false;
        }
    }

    /**
     * Queue a patch for transmission to the client.
     *
     * For component contexts, patches are forwarded to the parent page context's
     * channel: the SSE loop only reads from the top-level page channel.
     *
     * @param QueuedPatch $patch
     */
    public function queuePatch(array $patch): void {
        // Components don't have their own SSE reader: forward to the parent page.
        if ($this->componentManager->isComponent()) {
            $parent = $this->componentManager->getParentPageContext();
            if ($parent !== null) {
                $parent->getPatchManager()->queuePatch($patch);

                return;
            }
        }

        if ($this->useArray) {
            if ($this->closed) {
                $this->app->log('debug', "Patch rejected (channel closed) for context {$this->contextId}");

                return;
            }

            // Array-based queue for tests
            if (\count($this->patchChannel) >= self::CHANNEL_CAPACITY) {
                $this->patchChannel = $this->evictOne($this->patchChannel);
            }
            $this->patchChannel[] = $patch;
            if ($this->parked !== null) {
                $this->resumeParked();
            }
        } else {
            // OpenSwoole Channel for production
            $channel = $this->getPatchChannel();

            if ($channel->isFull()) {
                // A Channel is FIFO with no random access, so choosing a victim other
                // than the head means draining and refilling. Bounded at CHANNEL_CAPACITY
                // and only on overflow.
                $queued = $this->drainChannel($channel);
                $this->refillChannel($channel, $this->evictOne($queued));
            }

            if (!$channel->push($patch)) {
                // push() returns false on a closed channel, and isFull() still reports
                // true there while data remains, so without this check the patch was
                // dropped with no signal to the caller at all.
                $this->app->log(
                    'debug',
                    "Patch rejected (channel closed) for context {$this->contextId}"
                );
            }
        }
    }

    /**
     * Get next patch from the queue, parking for at most the keep-alive interval.
     *
     * Null means the park timed out, wakeConsumers() woke it, or the channel is closed (wasChannelClosed()).
     *
     * This loop has failed in both directions historically, so the contract is
     * deliberate. `pop(0)` does NOT mean "non-blocking": in OpenSwoole a timeout of
     * 0 means *no* timeout, so it parks until a push or close, which is why the
     * caller's liveness checks stopped running (idle connections were measured
     * stranded 61s past a 6s deadline). Conversely `pop($timeout)` returns instantly
     * on a CLOSED channel, which spun the loop at 100% CPU after context cleanup
     * (0c05bc7 introduced it, fcab883 reverted it two days later).
     *
     * Neither attempt discriminated the error code, which is what makes both safe:
     * a bounded park restores liveness, and callers use wasChannelClosed() to exit
     * instead of spinning. A closed channel still drains its buffer first
     * (errCode 0), so pending patches are delivered before the close is reported.
     *
     * @return null|QueuedPatch
     */
    public function getPatch(): ?array {
        $this->channelClosed = false;

        if ($this->useArray) {
            $fiber = $this->inProcess && $this->patchChannel === [] && !$this->closed ? \Fiber::getCurrent() : null;
            if ($fiber !== null) {
                $this->parked = $fiber;
                \Fiber::suspend();
            }

            // Array-based queue for tests
            if (empty($this->patchChannel)) {
                $this->channelClosed = $this->closed;

                return null;
            }

            return array_shift($this->patchChannel);
        }

        // Held locally so errCode is read from the channel that was popped, even once recreatePatchChannel() replaced it.
        $channel = $this->patchChannel;
        $result = $channel->pop($this->pollTimeout);

        if ($result === false) {
            $this->channelClosed = $channel->errCode === Channel::CHANNEL_CLOSED;

            return null;
        }

        return $result === self::WAKE ? null : $result;
    }

    /**
     * Wake every SSE loop parked in getPatch() on this context, so each re-checks its own state.
     * The channel stays open, so patches queued afterwards still carry over to the next stream.
     */
    public function wakeConsumers(): void {
        if ($this->useArray) {
            $this->resumeParked();

            return;
        }
        if (!$this->patchChannel instanceof Channel || Coroutine::getCid() <= 0) {
            return;
        }

        $channel = $this->patchChannel;
        // A parked consumer implies an empty channel, so each push goes straight to the longest-parked
        // consumer. Bounded by the first count, because a woken loop that parks again joins the back.
        for ($left = self::parkedConsumers($channel); $left > 0 && self::parkedConsumers($channel) > 0; --$left) {
            $channel->push(self::WAKE);
        }
    }

    /**
     * Put a patch that getPatch() returned but the SSE loop could not send back at the head of the queue.
     *
     * @param QueuedPatch $patch
     */
    public function returnPatch(array $patch): void {
        $channel = $this->patchChannel;
        $queued = [$patch, ...($channel instanceof Channel ? $this->drainChannel($channel) : $channel ?? [])];
        if (\count($queued) > self::CHANNEL_CAPACITY) {
            $queued = $this->evictOne($queued);
        }

        if ($channel instanceof Channel) {
            $this->refillChannel($channel, $queued);
        } else {
            $this->patchChannel = $queued;
        }
    }

    /**
     * Whether the last getPatch() returned null because the channel was closed
     * (cleanup, or replacement by recreatePatchChannel()) rather than merely idle.
     *
     * Callers must stop consuming when this is true. Continuing would spin (a
     * closed channel returns immediately regardless of timeout) and re-reading the
     * channel property would let a superseded SSE coroutine steal patches from the
     * live one.
     */
    public function wasChannelClosed(): bool {
        return $this->channelClosed;
    }

    /**
     * Sync current view and signals to the browser.
     *
     * @param bool $viewTransition false leaves out the view transition view() asked for, as on the render a stream sends when it connects
     */
    public function sync(bool $viewTransition = true): void {
        $context = $this->context();
        $page = $this->componentManager->getParentPageContext() ?? $context;
        if ($this->isHeldForSeed($page)) {
            return;
        }

        // Skip sync if view is not defined (e.g., during broadcast before client connects)
        if (!$context->hasView()) {
            // Still sync signals even without a view
            $this->app->log('debug', "Context {$this->contextId} has no view, syncing signals only");
            $this->syncSignalsOf($context);

            return;
        }

        $isPage = !$this->componentManager->isComponent();
        // Only a page with components has to know which of them its view rendered.
        if ($isPage && $this->componentManager->getComponents() !== []) {
            [$viewHtml, $renderedWithPage] = $this->componentManager->renderCollecting(static fn (): string => $context->renderView(isUpdate: true));
        } else {
            $viewHtml = $context->renderView(isUpdate: true);
            $renderedWithPage = [];
        }

        // A full document morphs <head> too: re-add the includes and the Dev Bar.
        $viewHtml = $this->app->decorateUpdate($viewHtml, $context);
        $pageFrame = null;

        if (!empty(trim($viewHtml))) {
            if (!$isPage) {
                $cssId = DomId::component($this->contextId);
                $patch = [
                    'type' => 'elements',
                    'content' => '<div id="' . $cssId . '">' . $viewHtml . '</div>',
                    'selector' => '#' . $cssId,
                ];
            } else {
                // For pages: Datastar matches by the root element's id in the fragment
                $patch = [
                    'type' => 'elements',
                    'content' => $viewHtml,
                ];
                $pageFrame = $viewHtml;
            }
            $transition = $viewTransition ? $context->viewTransition() : false;
            if ($transition !== false) {
                $patch['viewTransition'] = $transition;
            }
            $this->queuePatch($patch);
        }

        // Sync signals
        if (!$this->isHeldForSeed($page)) {
            $this->syncSignalsOf($context);
        }

        // For page (non-component) contexts, also sync all registered component sub-contexts.
        // Component patches are automatically forwarded to this page's channel via queuePatch().
        if ($isPage) {
            foreach ($this->componentManager->getComponents() as $componentId => $component) {
                // The page frame already carries this component as just rendered: only its signals are left.
                if ($pageFrame !== null && isset($renderedWithPage[$componentId]) && str_contains($pageFrame, $renderedWithPage[$componentId])) {
                    $component->syncSignals();

                    continue;
                }

                // Skip components with no dirty signals: their view is taken to be a pure
                // function of those signals. One that reads other state syncs itself
                // (sync() on its own context) or is reached by a broadcast of its scope.
                //
                // A component that declares NO signals must never be skipped: an empty
                // set makes hasChangedSignals() permanently false, so the component would
                // be skipped on every sync for the life of the process and the client
                // would freeze on its first-render value. No signals means we cannot prove
                // the view is a pure function of signals, so fall back to syncing.
                $componentSignals = $component->getSignalFactory();
                if ($componentSignals->hasSignals() && !$componentSignals->hasChangedSignals()) {
                    continue;
                }
                $viewTransition ? $component->sync() : $component->syncWithoutViewTransition();
            }
        }
    }

    /**
     * Sync only signals to the browser.
     */
    public function syncSignals(): void {
        $context = $this->context();
        if ($this->isHeldForSeed($this->componentManager->getParentPageContext() ?? $context)) {
            return;
        }

        $this->syncSignalsOf($context);
    }

    /**
     * Start tracking which TAB signals the action running in this coroutine syncs itself.
     *
     * @internal called by ActionHandler on the page context before the action runs
     */
    public function beginAction(): void {
        $this->queuedInAction[Coroutine::getCid()] = new \WeakMap();
    }

    /**
     * Send the TAB signals of this page and its components that changed and that no sync during
     * the action already queued unchanged, so an action needs no trailing syncSignals().
     *
     * @internal called by ActionHandler on the page context after the action, also when it threw
     */
    public function syncSignalsAfterAction(): void {
        $cid = Coroutine::getCid();
        $queued = $this->queuedInAction[$cid] ?? null;
        unset($this->queuedInAction[$cid]);

        if ($this->isHeldForSeed($this->componentManager->getParentPageContext() ?? $this->context())) {
            return;
        }

        $flat = [];

        /** @var list<Signal> $pending */
        $pending = [];
        self::collectChangedTabSignals($this->context(), $queued, $flat, $pending);
        if ($flat === []) {
            return;
        }

        $this->queuePatch([
            'type' => 'signals',
            'content' => $this->flatToNested($flat),
            'confirm' => static function () use ($pending): void {
                foreach ($pending as $signal) {
                    $signal->markSynced();
                }
            },
        ]);
    }

    /**
     * Signal values the first sync would send, for seeding the initial HTML.
     *
     * Covers this context's changed TAB signals and its scoped signals and, for a page, those of
     * its components. Nothing is marked synced: the first sync still sends them.
     *
     * @return array<string, mixed> Nested the same way as a signals patch
     */
    public function initialSignalValues(): array {
        return $this->flatToNested($this->collectInitialSignals());
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
        if (!$this->useArray) {
            $this->patchChannel->close();
        } elseif ($this->inProcess) {
            // As a closed Channel: the consumer takes what is queued, then sees the close.
            $this->closed = true;
            $this->resumeParked();
        } else {
            $this->patchChannel = [];
        }
    }

    /**
     * Recreate the patch channel (needed for SSE reconnections in new coroutines).
     */
    public function recreatePatchChannel(): void {
        if (!$this->useArray) {
            // Carry pending work across the reconnect. Discarding it destroyed every
            // queued patch on every reconnect (network blip, mobile handoff,
            // sleep/wake, proxy timeout), and contexts survive contextCleanupDelayMs,
            // so up to 5s of broadcasts could be thrown away. Signals now self-heal,
            // but one-shot scripts had no way back.
            $carried = $this->drainChannel($this->patchChannel);

            try {
                $this->patchChannel->close();
            } catch (\Throwable $e) {
                // Channel might already be closed, ignore
            }

            // Create new channel for the current coroutine
            $this->patchChannel = new Channel(self::CHANNEL_CAPACITY);
            $this->refillChannel($this->patchChannel, $carried);

            $this->app->log(
                'debug',
                "Recreated patch channel for context {$this->contextId} (carried " . \count($carried) . ' pending)'
            );
        } else {
            // In test mode the queue is a plain array; carry it across unchanged.
            $this->patchChannel = array_values($this->patchChannel);
            $this->closed = false;
        }
    }

    /**
     * Take the queued patches that no render sends again (isOneShot()), for the worker a tab moved to, with their
     * mode as its value. The other patches are dropped: the new worker's first sync sends view and signals.
     *
     * @return list<array{type: string, content: string, selector?: string, mode?: string, viewTransition?: string|true}>
     */
    public function takeOneShotPatches(): array {
        if ($this->useArray) {
            /** @var list<QueuedPatch> $queued */
            $queued = \is_array($this->patchChannel) ? $this->patchChannel : [];
            $this->patchChannel = [];
        } else {
            $queued = $this->patchChannel instanceof Channel ? $this->drainChannel($this->patchChannel) : [];
        }

        $taken = [];
        foreach ($queued as $patch) {
            if (!self::isOneShot($patch) || !\is_string($patch['content'])) {
                continue;
            }
            $one = ['type' => $patch['type'], 'content' => $patch['content']];
            if (isset($patch['selector'])) {
                $one['selector'] = $patch['selector'];
            }
            if (isset($patch['mode'])) {
                $one['mode'] = $patch['mode']->value;
            }
            if (isset($patch['viewTransition'])) {
                $one['viewTransition'] = $patch['viewTransition'];
            }
            $taken[] = $one;
        }

        return $taken;
    }

    /**
     * The values of this page's and its components' TAB signals that the worker a tab moved to cannot get from the
     * browser: those not yet sent to it, and with $serverOwned also those it does not send back. They count as sent
     * here, so a later call returns only values written since.
     *
     * @return array<string, mixed> by signal id
     */
    public function takeHandOverSignals(bool $serverOwned): array {
        $values = [];
        self::collectHandOverSignals($this->context(), $serverOwned, $values);

        return $values;
    }

    /**
     * Write the TAB signal values the worker that held the tab handed over that differ from this copy's, as the
     * server's, and send them with the view; see takeHandOverSignals().
     *
     * @param array<string, mixed> $values by signal id
     */
    public function applyHandedOverSignals(array $values): void {
        $written = false;
        foreach (self::tabSignalsById($this->context()) as $id => $signal) {
            if (\array_key_exists($id, $values) && $signal->getValue() !== $values[$id]) {
                $signal->setValue($values[$id]);
                $written = true;
            }
        }

        if ($written) {
            $this->sync();
        }
    }

    /**
     * Whether a patch has no re-send path, so that dropping it changes the page: a script, or an element
     * patch with a mode, as Context::patchElements() queues. Its target, such as a toast or a modal, may
     * lie outside the view, and a dropped Remove or Append is never repaired.
     *
     * The view frames sync() queues carry no mode, and a later sync renders their target again.
     *
     * @internal read by the queue's eviction and by the SSE loop's backlog drop
     *
     * @param QueuedPatch $patch
     */
    public static function isOneShot(array $patch): bool {
        if ($patch['type'] !== 'elements') {
            return $patch['type'] === 'script';
        }

        return isset($patch['mode']);
    }

    private function syncSignalsOf(Context $context): void {
        /** @var list<Signal> $pending */
        $pending = [];
        $updatedSignals = $this->prepareSignalsForPatch($pending);

        if (!empty($updatedSignals)) {
            $this->noteQueuedInAction($pending);

            // Acknowledgement is deferred to delivery. Marking these synced here,
            // at queue time, meant that any patch destroyed before transmission
            // (evicted when the queue filled, or discarded wholesale by
            // recreatePatchChannel() on an SSE reconnect) was never resent, leaving
            // the client permanently stale on a delta it never received.
            //
            // Because the confirm callback only runs after a successful write, a
            // patch that dies in the queue leaves its signals dirty and the next
            // syncSignals() re-includes them. Loss becomes self-healing.
            $this->queuePatch([
                'type' => 'signals',
                'content' => $updatedSignals,
                'confirm' => static function () use ($pending): void {
                    foreach ($pending as $signal) {
                        $signal->markSynced();
                    }
                },
            ]);
        }

        // Also sync scoped signals for all scopes this context belongs to
        $this->syncScopedSignals($context);
    }

    /**
     * Whether the page this manager feeds waits for its SSE connect to seed it. Its signals still
     * hold the defaults a revival declared, and anything queued now reaches the tab before the seed.
     */
    private function isHeldForSeed(Context $page): bool {
        if (!$page->isAwaitingSeed()) {
            return false;
        }

        $this->app->log('debug', "Sync held until the SSE connect seeds context {$page->getId()}");

        return true;
    }

    private function context(): Context {
        return $this->context->get() ?? throw new \LogicException("Patch manager of freed context {$this->contextId}");
    }

    /**
     * @return array<string, mixed>
     */
    private function collectInitialSignals(): array {
        $flat = [];

        foreach ($this->signalFactory->getTabSignals() as $id => $signal) {
            if ($signal->hasChanged()) {
                $flat[$id] = $signal->getValue();
            }
        }

        foreach ($this->context()->getScopes() as $scope) {
            if ($scope === Scope::TAB) {
                continue;
            }
            foreach ($this->app->getScopedSignals($scope) as $id => $signal) {
                $flat[$id] = $signal->getValue();
            }
        }

        if (!$this->componentManager->isComponent()) {
            foreach ($this->componentManager->getComponents() as $component) {
                $flat += $component->getPatchManager()->collectInitialSignals();
            }
        }

        return $flat;
    }

    /**
     * The changed TAB signals of $context and, recursively, its components, minus those queued
     * during the action at their current write count.
     *
     * @param null|\WeakMap<Signal, int> $queued
     * @param array<string, mixed>       $flat
     * @param list<Signal>               $pending
     */
    private static function collectChangedTabSignals(Context $context, ?\WeakMap $queued, array &$flat, array &$pending): void {
        foreach ($context->getSignalFactory()->getTabSignals() as $id => $signal) {
            if ($signal->hasChanged() && ($queued[$signal] ?? null) !== $signal->writeCount()) {
                $flat[$id] = $signal->getValue();
                $pending[] = $signal;
            }
        }

        foreach ($context->getComponentManager()->getComponents() as $component) {
            self::collectChangedTabSignals($component, $queued, $flat, $pending);
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function collectHandOverSignals(Context $context, bool $serverOwned, array &$values): void {
        foreach (self::tabSignalsById($context) as $id => $signal) {
            if ($signal->hasChanged() || ($serverOwned && !$signal->isClientWritable())) {
                $values[$id] = $signal->getValue();
                $signal->markSynced();
            }
        }
    }

    /**
     * The TAB signals of $context and, recursively, its components.
     *
     * @return array<string, Signal>
     */
    private static function tabSignalsById(Context $context): array {
        $signals = $context->getSignalFactory()->getTabSignals();
        foreach ($context->getComponentManager()->getComponents() as $component) {
            $signals += self::tabSignalsById($component);
        }

        return $signals;
    }

    /**
     * Record signals a sync queued while an action of this page runs in this coroutine.
     *
     * @param list<Signal> $signals
     */
    private function noteQueuedInAction(array $signals): void {
        $page = $this->componentManager->getParentPageContext()?->getPatchManager() ?? $this;
        $queued = $page->queuedInAction[Coroutine::getCid()] ?? null;
        if ($queued === null) {
            return;
        }

        foreach ($signals as $signal) {
            $queued[$signal] = $signal->writeCount();
        }
    }

    /**
     * Choose and remove one victim from a full queue.
     *
     * View frames go first: a later sync renders the same target again. Signal patches are
     * next, since an undelivered signal stays dirty and is resent (acknowledgement happens at
     * delivery, see syncSignals()). One-shot patches (isOneShot()) have no re-send path: a
     * dropped redirect is a broken login flow, a dropped Append chunk a gap in the output, a
     * dropped Remove a toast that never goes. The oldest of them goes only when the queue
     * holds nothing else.
     *
     * @param list<QueuedPatch> $patches
     *
     * @return list<QueuedPatch>
     */
    private function evictOne(array $patches): array {
        foreach (['elements', 'signals'] as $preferredType) {
            foreach ($patches as $i => $patch) {
                if ($patch['type'] === $preferredType && !self::isOneShot($patch)) {
                    unset($patches[$i]);
                    $this->app->log(
                        'debug',
                        "Evicted oldest {$preferredType} patch for context {$this->contextId} - queue full"
                    );

                    return array_values($patches);
                }
            }
        }

        $dropped = array_shift($patches)['type'] ?? 'none';
        $this->app->log(
            'warning',
            "Queue full of one-shot patches for context {$this->contextId} - dropped the oldest ({$dropped})"
        );

        return $patches;
    }

    /**
     * Drain every currently-queued patch without blocking.
     *
     * Uses a positive timeout rather than pop(0): in OpenSwoole a timeout of 0 means
     * "no timeout" and parks the coroutine, so a consumer taking the last item between
     * our isEmpty() check and the pop would hang the producer forever.
     *
     * @return list<QueuedPatch>
     */
    private function drainChannel(Channel $channel): array {
        $drained = [];

        while (!$channel->isEmpty()) {
            $patch = $channel->pop(0.001);
            if ($patch === false) {
                break;
            }
            if ($patch !== self::WAKE) {
                $drained[] = $patch;
            }
        }

        return $drained;
    }

    private static function parkedConsumers(Channel $channel): int {
        return (int) ($channel->stats()['consumer_num'] ?? 0);
    }

    /**
     * In process: run the parked SSE loop until it parks again, as a Channel push or close resumes a parked consumer.
     */
    private function resumeParked(): void {
        $fiber = $this->parked;
        if ($fiber === null || !$fiber->isSuspended()) {
            return;
        }

        $this->parked = null;
        $fiber->resume();
    }

    /**
     * Push previously drained patches back, preserving order.
     *
     * @param list<QueuedPatch> $patches
     */
    private function refillChannel(Channel $channel, array $patches): void {
        foreach ($patches as $patch) {
            if ($channel->isFull() || !$channel->push($patch)) {
                $this->app->log(
                    'warning',
                    "Lost a patch refilling the queue for context {$this->contextId}"
                );
            }
        }
    }

    /**
     * Sync scoped signals for all scopes this context belongs to.
     */
    private function syncScopedSignals(Context $context): void {
        $flat = [];

        foreach ($context->getScopes() as $scope) {
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
     * Collects the changed signals but deliberately does NOT mark them synced.
     * See syncSignals() for why acknowledgement is deferred until delivery.
     *
     * @param list<Signal> $pending filled with the signals this patch carries
     *
     * @return array<string, mixed> Nested structure of changed signals
     */
    private function prepareSignalsForPatch(array &$pending = []): array {
        // Components use their own signals
        $signalsToCheck = $this->signalFactory->getTabSignals();

        $flat = [];

        foreach ($signalsToCheck as $id => $signal) {
            if ($signal->hasChanged()) {
                $flat[$id] = $signal->getValue();
                $pending[] = $signal;
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
            if (str_contains($key, '.')) {
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
     *
     * @return Channel|list<QueuedPatch>
     */
    private function getPatchChannel(): array|Channel {
        return $this->componentManager->getParentPageContext()?->getPatchManager()->patchChannel ?? $this->patchChannel;
    }
}
