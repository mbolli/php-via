<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Composition;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\OnCleanup;
use Mbolli\PhpVia\Attributes\OnDisconnect;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Scope;

/**
 * Reflection metadata for a page/component class.
 *
 * Analyzed once per class and cached statically.
 */
final class ClassMetadata {
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string>                                              $signals         Property names annotated #[Signal] with TAB scope
     * @param array<array{prop: string, scope: string}>                  $scopedSignals   #[Signal] properties with a non-TAB scope
     * @param array<string, true>                                        $atomicSignals   #[Signal(atomic: true)] properties, keyed by name
     * @param array<string, bool>                                        $clientWritable  Explicit #[Signal(clientWritable: ...)] per property
     * @param array<string>                                              $persists        Property names annotated #[Persist]
     * @param array<array{method: string, name: string, scope: ?string}> $actions
     * @param array<string, mixed>                                       $defaults        Default value per annotated property
     * @param array<array{name: string, type: string}>                   $viewRouteParams Route params declared on view() beyond Context
     * @param list<string>                                               $onCleanup       #[OnCleanup] method names in declaration order
     */
    private function __construct(
        public readonly string $class,
        public readonly array $signals,
        public readonly array $scopedSignals,
        public readonly array $atomicSignals,
        public readonly array $clientWritable,
        public readonly array $persists,
        public readonly array $actions,
        public readonly array $defaults,
        public readonly array $viewRouteParams,
        /** Primary scope from #[Broadcast] on the class, or null. */
        public readonly ?string $broadcastScope,
        public readonly array $onCleanup,
    ) {}

    /**
     * Analyze a class and return cached metadata.
     *
     * @param class-string $class
     *
     * @throws \InvalidArgumentException if the class has no public view(Context) method
     * @throws \LogicException           if a method carries the removed #[OnDisconnect]
     */
    public static function analyze(string $class): self {
        if (isset(self::$cache[$class])) {
            return self::$cache[$class];
        }

        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Class '{$class}' does not exist.");
        }

        $rc = new \ReflectionClass($class);

        // Validate view() method
        if (!$rc->hasMethod('view')) {
            throw new \InvalidArgumentException(
                "Class '{$class}' must have a public view(Context \$ctx) method."
            );
        }
        $viewMethod = $rc->getMethod('view');
        if (!$viewMethod->isPublic()) {
            throw new \InvalidArgumentException(
                "Class '{$class}'::view() must be public."
            );
        }

        // Collect reactive properties
        $signals = [];
        $scopedSignals = [];
        $atomicSignals = [];
        $clientWritable = [];
        $persists = [];
        $defaults = [];

        foreach ($rc->getProperties() as $prop) {
            if (!$prop->isPublic() && !self::hasAnyReactiveAttribute($prop)) {
                continue;
            }

            $name = $prop->getName();
            $default = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;

            $signalAttr = self::getAttr($prop, Signal::class);
            if ($signalAttr instanceof Signal) {
                if ($signalAttr->scope === Scope::TAB) {
                    $signals[] = $name;
                } else {
                    $scopedSignals[] = ['prop' => $name, 'scope' => $signalAttr->scope];
                }
                if ($signalAttr->atomic) {
                    // Reject at mount rather than letting the first action throw: the delta is
                    // only meaningful for a number, and a string property declared atomic is a
                    // misunderstanding worth naming immediately.
                    self::assertAtomicIsInt($class, $prop, $default);
                    $atomicSignals[$name] = true;
                }
                if ($signalAttr->clientWritable !== null) {
                    $clientWritable[$name] = $signalAttr->clientWritable;
                }
                $defaults[$name] = $default;

                continue;
            }

            if (self::getAttr($prop, Persist::class) instanceof Persist) {
                $persists[] = $name;
                $defaults[$name] = $default;
            }
        }

        // Collect #[Action] and #[OnCleanup] methods
        $actions = [];
        $onCleanup = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            // @phpstan-ignore classConstant.deprecatedClass (this guard is why the class still exists)
            if ($method->getAttributes(OnDisconnect::class) !== []) {
                throw new \LogicException(
                    "{$class}::{$methodName}() uses #[OnDisconnect], which was removed in php-via 0.14. "
                    . 'Use #[OnCleanup]: it runs at the same moment, when the context is destroyed.'
                );
            }

            $actionAttr = self::getMethodAttr($method, Action::class);
            if ($actionAttr instanceof Action) {
                $actions[] = [
                    'method' => $methodName,
                    'name' => $actionAttr->name ?? $methodName,
                    'scope' => $actionAttr->scope,
                ];
            }

            if (self::getMethodAttr($method, OnCleanup::class) instanceof OnCleanup) {
                $onCleanup[] = $methodName;
            }
        }

        // Collect #[Broadcast] primary scope from the class
        $broadcastScope = null;
        $broadcastAttrs = $rc->getAttributes(Broadcast::class);
        if ($broadcastAttrs !== []) {
            $broadcastScope = $broadcastAttrs[0]->newInstance()->scope;
        }

        // Collect route params from view() beyond the first Context param
        $viewRouteParams = [];
        $viewParams = $viewMethod->getParameters();
        foreach (\array_slice($viewParams, 1) as $param) {
            $typeName = 'string';
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType) {
                $typeName = $type->getName();
            }
            $viewRouteParams[] = ['name' => $param->getName(), 'type' => $typeName];
        }

        return self::$cache[$class] = new self(
            class: $class,
            signals: $signals,
            scopedSignals: $scopedSignals,
            atomicSignals: $atomicSignals,
            clientWritable: $clientWritable,
            persists: $persists,
            actions: $actions,
            defaults: $defaults,
            viewRouteParams: $viewRouteParams,
            broadcastScope: $broadcastScope,
            onCleanup: $onCleanup,
        );
    }

    /**
     * @throws \InvalidArgumentException if an atomic property is not an integer
     */
    private static function assertAtomicIsInt(string $class, \ReflectionProperty $prop, mixed $default): void {
        $type = $prop->getType();
        $isIntType = $type instanceof \ReflectionNamedType && $type->getName() === 'int';

        if ($isIntType || (!$type instanceof \ReflectionNamedType && \is_int($default))) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            '%s::$%s is declared #[Signal(atomic: true)] but is not an int. atomic applies the '
            . "action's net CHANGE with Signal::increment(), which only has a meaning for a "
            . 'number. Drop atomic, or use $ctx->getSignal(\'%s\')->mutate() for a race-free '
            . 'read-modify-write on a non-integer.',
            $class,
            $prop->getName(),
            $prop->getName(),
        ));
    }

    private static function hasAnyReactiveAttribute(\ReflectionProperty $prop): bool {
        foreach ([Signal::class, Persist::class] as $attrClass) {
            if (self::getAttr($prop, $attrClass) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function getAttr(\ReflectionProperty $prop, string $attrClass): ?object {
        $attrs = $prop->getAttributes($attrClass);

        return $attrs !== [] ? $attrs[0]->newInstance() : null;
    }

    private static function getMethodAttr(\ReflectionMethod $method, string $attrClass): ?object {
        $attrs = $method->getAttributes($attrClass);

        return $attrs !== [] ? $attrs[0]->newInstance() : null;
    }
}
