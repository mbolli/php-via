<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Composition;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\TypeCaster;
use Mbolli\PhpVia\Via;

/**
 * Builds the page/component setup closure from ClassMetadata.
 *
 * The returned closure is passed directly to $app->page() or
 * $ctx->component(), so the composition API is implemented entirely
 * on top of the existing closure-based infrastructure.
 */
final class PageMount {
    /**
     * Each context's runners for its #[Action(scope: ...)] methods. A scoped action is registered
     * once per scope, so the shared callback looks up the calling context's own instance here.
     *
     * @var null|\WeakMap<Context, array<string, \Closure(Context): void>>
     */
    private static ?\WeakMap $scopedRunners = null;

    /**
     * The instances running an #[Action] method, with how many: their properties may hold writes not synced back yet.
     *
     * @var null|\WeakMap<object, int>
     */
    private static ?\WeakMap $acting = null;

    /**
     * Build a setup closure for the given class metadata.
     *
     * @param ClassMetadata             $meta    Reflection metadata for the page/component class
     * @param Via                       $app     Via application instance
     * @param null|(callable(): object) $factory Optional factory: called instead of new $class() per connection
     */
    public static function buildClosure(ClassMetadata $meta, Via $app, ?callable $factory = null): \Closure {
        return static function (Context $ctx) use ($meta, $factory): void {
            // 1. Create instance (factory or zero-arg constructor)
            $class = $meta->class;
            $instance = $factory !== null ? ($factory)() : new $class();

            // 2. Register #[Action] methods. An unscoped one is a per-tab action; a scoped one is
            //    registered once per scope and runs on the calling context's instance.
            $joinScopes = [];
            foreach ($meta->actions as ['method' => $method, 'name' => $name, 'scope' => $scope]) {
                $run = self::actionRunner($instance, $method, $meta);
                if ($scope === null || $scope === Scope::TAB) {
                    $ctx->action($run, $name);

                    continue;
                }

                $action = $ctx->scopedAction(static function (Context $caller, string $actionId): void {
                    self::runScoped($caller, $actionId);
                }, $name, $scope);
                self::bindScoped($ctx, $action->id(), $run);
                // ROUTE and GLOBAL actions are found without membership. A session or custom scope is joined,
                // so that its registration goes with the scope's last context.
                if ($scope !== Scope::ROUTE && $scope !== Scope::GLOBAL) {
                    $joinScopes[] = $scope;
                }
            }

            // 3. #[Broadcast] only sets the target of $ctx->broadcast().
            if ($meta->broadcastScope !== null) {
                $ctx->scope($meta->broadcastScope);
            }

            // 4. Register signals, each with the scope it declares. A scoped signal joins its scope.
            foreach ($meta->signals as $prop) {
                $signal = $ctx->signal($meta->defaults[$prop], $prop, Scope::TAB, clientWritable: $meta->clientWritable[$prop] ?? null);
                if (\array_key_exists($prop, $meta->clientTypes)) {
                    $signal->acceptClientTypes($meta->clientTypes[$prop]);
                }
            }
            foreach ($meta->scopedSignals as ['prop' => $prop, 'scope' => $scope]) {
                $signal = $ctx->signal($meta->defaults[$prop], $prop, $scope, clientWritable: $meta->clientWritable[$prop] ?? null);
                if (\array_key_exists($prop, $meta->clientTypes)) {
                    $signal->acceptClientTypes($meta->clientTypes[$prop]);
                }
            }
            // #[Persist] → no signal, pure instance property

            // 5. Join the session and custom scopes of scoped actions, so that executeAction() finds them.
            foreach (array_unique($joinScopes) as $scope) {
                $ctx->addScope($scope);
            }

            // 6. Hydrate instance from current signal values
            self::hydrate($instance, $meta, $ctx);

            // 7. Register #[OnCleanup] methods in declaration order, on a freshly hydrated instance.
            if ($meta->onCleanup !== []) {
                $ctx->onCleanup(static function (Context $ctx) use ($instance, $meta): void {
                    self::hydrate($instance, $meta, $ctx);
                });
                foreach ($meta->onCleanup as $method) {
                    $ctx->onCleanup(static function (Context $ctx) use ($instance, $method): void {
                        $instance->{$method}($ctx);
                    });
                }
            }

            // 8. Set up view: inject route params if declared on view()
            $viewArgs = [$ctx];
            foreach ($meta->viewRouteParams as ['name' => $paramName, 'type' => $paramType]) {
                $raw = $ctx->getPathParam($paramName);
                $viewArgs[] = TypeCaster::cast($raw, $paramType);
            }
            $instance->view(...$viewArgs);

            // Another tab's write reaches this tab as a broadcast render, so a scoped property is read again first.
            if ($meta->scopedSignals !== []) {
                $ctx->beforeEachRender(static function () use ($instance, $meta, $ctx): void {
                    if (!isset(self::acting()[$instance])) {
                        self::hydrateScoped($instance, $meta, $ctx);
                    }
                });
            }
        };
    }

    /**
     * The callback of one #[Action] method on one instance.
     *
     * @return \Closure(Context): void
     */
    private static function actionRunner(object $instance, string $method, ClassMetadata $meta): \Closure {
        return static function (Context $ctx) use ($instance, $method, $meta): void {
            // Re-hydrate first: the client may have changed #[Signal] values via data-bind.
            self::hydrate($instance, $meta, $ctx);

            // Record what the action starts from, so syncBack() can tell an
            // untouched property from a changed one and size an atomic delta.
            $before = self::snapshot($instance, $meta, $ctx);
            $acting = self::acting();
            $acting[$instance] = ($acting[$instance] ?? 0) + 1;

            try {
                $instance->{$method}($ctx);
            } finally {
                // A closure action's signal writes stay when it throws, so these do too.
                try {
                    self::syncBack($instance, $meta, $ctx, $before);
                } finally {
                    if (--$acting[$instance] <= 0) {
                        unset($acting[$instance]);
                    }
                }
            }

            // Flush TAB signal changes to the current client
            $ctx->syncSignals();
        };
    }

    /**
     * @param \Closure(Context): void $run
     */
    private static function bindScoped(Context $ctx, string $actionId, \Closure $run): void {
        $runners = self::scopedRunners();
        if (!isset($runners[$ctx])) {
            // Dropped with the context's other action callbacks, so an instance that keeps its
            // Context does not leave a cycle for PHP's collector.
            $ctx->onCleanup(static function (Context $ctx) use ($runners): void {
                unset($runners[$ctx]);
            });
            $runners[$ctx] = [];
        }
        $runners[$ctx] = [...$runners[$ctx], $actionId => $run];
    }

    /**
     * Run a scoped #[Action] on the calling context's own instance.
     *
     * @throws \RuntimeException if neither the caller nor one of its components mounted it
     */
    private static function runScoped(Context $caller, string $actionId): void {
        [$owner, $run] = self::findScopedRunner($caller, $actionId)
            ?? throw new \RuntimeException("Action not found: {$actionId} (no instance on this context declares it)");
        $run($owner);
    }

    /**
     * The caller's runner for $actionId, else its component's: Context::executeAction() tries
     * the page's ROUTE and GLOBAL actions before its components'. A component's id carries its namespace.
     *
     * @return null|array{Context, \Closure(Context): void}
     */
    private static function findScopedRunner(Context $ctx, string $actionId): ?array {
        $run = self::scopedRunners()[$ctx][$actionId] ?? null;
        if ($run !== null) {
            return [$ctx, $run];
        }
        foreach ($ctx->getComponentRegistry() as $component) {
            $found = self::findScopedRunner($component, $actionId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @return \WeakMap<Context, array<string, \Closure(Context): void>>
     */
    private static function scopedRunners(): \WeakMap {
        return self::$scopedRunners ??= new \WeakMap();
    }

    /**
     * @return \WeakMap<object, int>
     */
    private static function acting(): \WeakMap {
        return self::$acting ??= new \WeakMap();
    }

    /**
     * Reactive property names: TAB #[Signal] plus scoped #[Signal(Scope::X)].
     * #[Persist] properties are excluded: they live only on the instance.
     *
     * @return array<string>
     */
    private static function reactiveProps(ClassMetadata $meta): array {
        $props = $meta->signals;
        foreach ($meta->scopedSignals as ['prop' => $prop]) {
            $props[] = $prop;
        }

        return $props;
    }

    /**
     * Copy current signal values onto the instance's reactive properties.
     * #[Persist] properties are intentionally skipped: they live on the instance.
     */
    private static function hydrate(object $instance, ClassMetadata $meta, Context $ctx): void {
        foreach (self::reactiveProps($meta) as $prop) {
            $signal = $ctx->getSignal($prop);
            if ($signal !== null) {
                $instance->{$prop} = $signal->getValue();
            }
        }
    }

    /**
     * Copy the current values of the scoped signals onto their properties, which other tabs and workers write.
     */
    private static function hydrateScoped(object $instance, ClassMetadata $meta, Context $ctx): void {
        foreach ($meta->scopedSignals as ['prop' => $prop]) {
            $signal = $ctx->getSignal($prop);
            if ($signal !== null) {
                $instance->{$prop} = $signal->getValue();
            }
        }
    }

    /**
     * Capture each reactive property's value and its signal's write count before the action.
     *
     * @return array<string, array{value: mixed, writes: int}>
     */
    private static function snapshot(object $instance, ClassMetadata $meta, Context $ctx): array {
        $before = [];
        foreach (self::reactiveProps($meta) as $prop) {
            $signal = $ctx->getSignal($prop);
            if ($signal !== null) {
                $before[$prop] = ['value' => $instance->{$prop}, 'writes' => $signal->writeCount()];
            }
        }

        return $before;
    }

    /**
     * Write changed instance property values back into their signals.
     * Signal::setValue() handles auto-broadcast for scoped signals.
     *
     * Three cases, in order:
     *
     *  1. The action wrote the signal directly (its write count moved). The explicit write wins
     *     and the property is left alone: it is either stale or merely mirroring what was just
     *     written. Assigning it back used to discard the write entirely: an action whose whole
     *     body was `$ctx->getSignal('votes')->increment()` ended every round back where it
     *     started, because the untouched property still held the pre-increment value.
     *
     *  2. The property is unchanged. Nothing to write. Skipping matters beyond saving a
     *     round trip: on a shared signal, assigning the hydrated value back would clobber
     *     whatever another worker wrote while this action was running.
     *
     *  3. The property changed. #[Signal(atomic: true)] applies the difference through
     *     increment() so concurrent workers add up; everything else assigns, as before.
     *
     * @param array<string, array{value: mixed, writes: int}> $before from snapshot()
     */
    private static function syncBack(object $instance, ClassMetadata $meta, Context $ctx, array $before): void {
        foreach (self::reactiveProps($meta) as $prop) {
            $signal = $ctx->getSignal($prop);
            if ($signal === null || !isset($before[$prop])) {
                continue;
            }

            if ($signal->writeCount() !== $before[$prop]['writes']) {
                continue;
            }

            $value = $instance->{$prop};
            $wasValue = $before[$prop]['value'];
            if ($value === $wasValue) {
                continue;
            }

            if (isset($meta->atomicSignals[$prop]) && \is_int($value) && \is_int($wasValue)) {
                $signal->increment($value - $wasValue);

                continue;
            }

            $signal->setValue($value);
        }
    }
}
