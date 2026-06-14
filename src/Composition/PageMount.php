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
     * Build a setup closure for the given class metadata.
     *
     * @param ClassMetadata             $meta    Reflection metadata for the page/component class
     * @param Via                       $app     Via application instance
     * @param (callable(): object)|null $factory Optional factory: called instead of new $class() per connection
     */
    public static function buildClosure(ClassMetadata $meta, Via $app, ?callable $factory = null): \Closure {
        return static function (Context $ctx) use ($meta, $factory): void {
            // 1. Create instance (factory or zero-arg constructor)
            $class = $meta->class;
            $instance = $factory !== null ? ($factory)() : new $class();

            // 2. Register signals. #[Signal] (TAB) is created with an explicit TAB
            //    scope so it never inherits a non-TAB primary scope set by #[Broadcast].
            foreach ($meta->signals as $prop) {
                $ctx->signal($meta->defaults[$prop], $prop, Scope::TAB);
            }
            // 3. Register scoped #[Signal(Scope::X)] signals. ROUTE is expanded to the
            //    per-route scope here (SignalFactory resolves SESSION on its own).
            foreach ($meta->scopedSignals as ['prop' => $prop, 'scope' => $scope]) {
                $ctx->signal($meta->defaults[$prop], $prop, self::resolveScope($scope, $ctx));
            }
            // #[Persist] → no signal, pure instance property

            // 4. Register context in every scope used by its scoped signals so that:
            //    - syncScopedSignals() includes these signals in patches
            //    - broadcast() reaches this context via the scope registry
            $addedScopes = [];
            foreach ($meta->scopedSignals as ['prop' => $prop]) {
                $signal = $ctx->getSignal($prop);
                if ($signal === null) {
                    continue;
                }
                $signalScope = $signal->getScope();
                if ($signalScope !== null && !\in_array($signalScope, $addedScopes, true)) {
                    $ctx->addScope($signalScope);
                    $addedScopes[] = $signalScope;
                }
            }

            // 5. Apply #[Broadcast] primary scope — AFTER signal registration so that
            //    un-scoped #[Signal] properties are unaffected by it. This only sets
            //    the target of $ctx->broadcast() (with no argument).
            if ($meta->broadcastScope !== null) {
                $ctx->scope($meta->broadcastScope);
            }

            // 6. Hydrate instance from current signal values
            self::hydrate($instance, $meta, $ctx);

            // 7. Register #[Action] methods as named actions
            foreach ($meta->actions as $actionMeta) {
                $method = $actionMeta['method'];
                $name = $actionMeta['name'];
                $scope = $actionMeta['scope'];

                $ctx->action(
                    static function (Context $ctx) use ($instance, $method, $meta): void {
                        // Re-hydrate reactive properties before running the action
                        // (client may have mutated #[Signal] values via data-bind)
                        self::hydrate($instance, $meta, $ctx);

                        // Run the action method
                        $instance->{$method}($ctx);

                        // Sync changed values back to signals
                        // Signal::setValue() auto-broadcasts for scoped signals
                        self::syncBack($instance, $meta, $ctx);

                        // Flush TAB signal changes to the current client
                        $ctx->syncSignals();
                    },
                    $name,
                    $scope,
                );
            }

            // 8. Register lifecycle hooks. Handlers are NOT re-hydrated first — they
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

            // 9. Set up view — inject route params if declared on view()
            $viewArgs = [$ctx];
            foreach ($meta->viewRouteParams as ['name' => $paramName, 'type' => $paramType]) {
                $raw = $ctx->getPathParam($paramName);
                $viewArgs[] = TypeCaster::cast($raw, $paramType);
            }
            $instance->view(...$viewArgs);
        };
    }

    /**
     * Resolve a declared signal scope to its concrete runtime scope.
     * Scope::ROUTE is expanded to the per-route scope (matching Context::scope());
     * Scope::SESSION is left for SignalFactory to resolve to session:{id}.
     */
    private static function resolveScope(string $scope, Context $ctx): string {
        return $scope === Scope::ROUTE ? Scope::routeScope($ctx->getRoute()) : $scope;
    }

    /**
     * Reactive property names: TAB #[Signal] plus scoped #[Signal(Scope::X)].
     * #[Persist] properties are excluded — they live only on the instance.
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
     * #[Persist] properties are intentionally skipped — they live on the instance.
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
     * Write instance property values back into their signals.
     * Signal::setValue() handles auto-broadcast for scoped signals.
     */
    private static function syncBack(object $instance, ClassMetadata $meta, Context $ctx): void {
        foreach (self::reactiveProps($meta) as $prop) {
            $signal = $ctx->getSignal($prop);
            if ($signal !== null) {
                $signal->setValue($instance->{$prop});
            }
        }
    }
}
