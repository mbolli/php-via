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

            // 2. Register signals for every reactive property
            foreach ($meta->signals as $prop) {
                $ctx->signal($meta->defaults[$prop], $prop);
            }
            foreach ($meta->stateSessions as $prop) {
                $ctx->signal($meta->defaults[$prop], $prop, Scope::SESSION);
            }
            foreach ($meta->stateApps as $prop) {
                $ctx->signal($meta->defaults[$prop], $prop, Scope::GLOBAL);
            }
            // #[StateTab] → no signal, pure instance property

            // 2b. Register context in every scope used by its scoped signals so that:
            //     - syncScopedSignals() includes these signals in patches
            //     - broadcast() reaches this context via the scope registry
            $addedScopes = [];
            foreach ([...$meta->stateSessions, ...$meta->stateApps] as $prop) {
                $signal = $ctx->getSignal($prop);
                if ($signal === null) {
                    continue;
                }
                $scope = $signal->getScope();
                if ($scope !== null && !\in_array($scope, $addedScopes, true)) {
                    $ctx->addScope($scope);
                    $addedScopes[] = $scope;
                }
            }

            // 3. Hydrate instance from current signal values
            self::hydrate($instance, $meta, $ctx);

            // 4. Register #[Action] methods as named actions
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
                        // Signal::setValue() auto-broadcasts for SESSION/GLOBAL scoped signals
                        self::syncBack($instance, $meta, $ctx);

                        // Flush TAB signal changes to the current client
                        $ctx->syncSignals();
                    },
                    $name,
                    $scope,
                );
            }

            // 5. Set up view — inject route params if declared on view()
            $viewArgs = [$ctx];
            foreach ($meta->viewRouteParams as ['name' => $paramName, 'type' => $paramType]) {
                $raw = $ctx->getPathParam($paramName);
                $viewArgs[] = TypeCaster::cast($raw, $paramType);
            }
            $instance->view(...$viewArgs);
        };
    }

    /**
     * Copy current signal values onto the instance's reactive properties.
     * #[StateTab] properties are intentionally skipped — they live on the instance.
     */
    private static function hydrate(object $instance, ClassMetadata $meta, Context $ctx): void {
        foreach ([...$meta->signals, ...$meta->stateSessions, ...$meta->stateApps] as $prop) {
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
        foreach ([...$meta->signals, ...$meta->stateSessions, ...$meta->stateApps] as $prop) {
            $signal = $ctx->getSignal($prop);
            if ($signal !== null) {
                $signal->setValue($instance->{$prop});
            }
        }
    }
}
