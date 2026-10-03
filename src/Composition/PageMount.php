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

            // 2. Register #[Action] methods. An unscoped one is a per-tab action; it is registered
            //    before #[Broadcast] sets the primary scope so that it cannot inherit it.
            $joinScopes = [];
            foreach ($meta->actions as ['method' => $method, 'name' => $name, 'scope' => $scope]) {
                $run = self::actionRunner($instance, $method, $meta);
                if ($scope === null) {
                    $ctx->action($run, $name);

                    continue;
                }

                self::bindScoped($ctx, $name, $run);
                $ctx->action(static function (Context $caller) use ($name): void {
                    self::runScoped($caller, $name);
                }, $name, $scope);
                // Context::executeAction() looks in the ROUTE and GLOBAL scopes without membership.
                if ($scope !== Scope::ROUTE && $scope !== Scope::GLOBAL) {
                    $joinScopes[] = $scope;
                }
            }

            // 3. #[Broadcast] only sets the target of $ctx->broadcast(). It comes before the joins
            //    below because scope() replaces the context's scope list.
            if ($meta->broadcastScope !== null) {
                $ctx->scope($meta->broadcastScope);
            }

            // 4. Register signals, each with the scope it declares.
            foreach ($meta->signals as $prop) {
                $ctx->signal($meta->defaults[$prop], $prop, Scope::TAB, clientWritable: $meta->clientWritable[$prop] ?? null);
            }
            foreach ($meta->scopedSignals as ['prop' => $prop, 'scope' => $scope]) {
                $signal = $ctx->signal($meta->defaults[$prop], $prop, $scope, clientWritable: $meta->clientWritable[$prop] ?? null);
                if ($signal->getScope() !== null) {
                    $joinScopes[] = $signal->getScope();
                }
            }
            // #[Persist] → no signal, pure instance property

            // 5. Join the scopes of scoped signals and actions, so that their patches and
            //    broadcasts reach this context and executeAction() finds the actions.
            foreach (array_unique($joinScopes) as $scope) {
                $ctx->addScope($scope);
            }

            // 6. Hydrate instance from current signal values
            self::hydrate($instance, $meta, $ctx);

            // 7. Register lifecycle hooks. Handlers are NOT re-hydrated first: they
            //    do cleanup (presence updates, broadcasts) rather than read signals.
            if ($meta->onDisconnect !== null) {
                $method = $meta->onDisconnect;
                $ctx->onDisconnect(static function (Context $ctx) use ($instance, $method): void {
                    $instance->{$method}($ctx);
                });
            }
            if ($meta->onCleanup !== null) {
                $method = $meta->onCleanup;
                $ctx->onCleanup(static function (Context $ctx) use ($instance, $method): void {
                    $instance->{$method}($ctx);
                });
            }

            // 8. Set up view: inject route params if declared on view()
            $viewArgs = [$ctx];
            foreach ($meta->viewRouteParams as ['name' => $paramName, 'type' => $paramType]) {
                $raw = $ctx->getPathParam($paramName);
                $viewArgs[] = TypeCaster::cast($raw, $paramType);
            }
            $instance->view(...$viewArgs);
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

            $instance->{$method}($ctx);

            // Sync changed values back to signals
            // Signal::setValue() auto-broadcasts for scoped signals
            self::syncBack($instance, $meta, $ctx, $before);

            // Flush TAB signal changes to the current client
            $ctx->syncSignals();
        };
    }

    /**
     * @param \Closure(Context): void $run
     */
    private static function bindScoped(Context $ctx, string $name, \Closure $run): void {
        $runners = self::scopedRunners();
        if (!isset($runners[$ctx])) {
            // Dropped with the context's other action callbacks, so an instance that keeps its
            // Context does not leave a cycle for PHP's collector.
            $ctx->onCleanup(static function (Context $ctx) use ($runners): void {
                unset($runners[$ctx]);
            });
            $runners[$ctx] = [];
        }
        $runners[$ctx] = [...$runners[$ctx], $name => $run];
    }

    /**
     * Run a scoped #[Action] on the calling context's own instance.
     *
     * @throws \RuntimeException if neither the caller nor one of its components mounted it
     */
    private static function runScoped(Context $caller, string $name): void {
        [$owner, $run] = self::findScopedRunner($caller, $name)
            ?? throw new \RuntimeException("Action not found: {$name} (no instance on this context declares it)");
        $run($owner);
    }

    /**
     * The caller's runner for $name, else the first component's: Context::executeAction() tries
     * the page's ROUTE and GLOBAL actions before its components'.
     *
     * @return null|array{Context, \Closure(Context): void}
     */
    private static function findScopedRunner(Context $ctx, string $name): ?array {
        $run = self::scopedRunners()[$ctx][$name] ?? null;
        if ($run !== null) {
            return [$ctx, $run];
        }
        foreach ($ctx->getComponentRegistry() as $component) {
            $found = self::findScopedRunner($component, $name);
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
