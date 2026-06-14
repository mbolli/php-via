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
     * @param array<string>                              $signals         Property names annotated #[Signal] with TAB scope
     * @param array<array{prop: string, scope: string}>  $scopedSignals   #[Signal] properties with a non-TAB scope
     * @param array<string>                              $persists        Property names annotated #[Persist]
     * @param array<array{method: string, name: string, scope: ?string}> $actions
     * @param array<string, mixed>                       $defaults        Default value per annotated property
     * @param array<array{name: string, type: string}>  $viewRouteParams Route params declared on view() beyond Context
     */
    private function __construct(
        public readonly string $class,
        public readonly array $signals,
        public readonly array $scopedSignals,
        public readonly array $persists,
        public readonly array $actions,
        public readonly array $defaults,
        public readonly array $viewRouteParams,
        /** Primary scope from #[Broadcast] on the class, or null. */
        public readonly ?string $broadcastScope,
        /** Method name annotated #[OnDisconnect], or null. */
        public readonly ?string $onDisconnect,
        /** Method name annotated #[OnCleanup], or null. */
        public readonly ?string $onCleanup,
    ) {}

    /**
     * Analyze a class and return cached metadata.
     *
     * @param class-string $class
     *
     * @throws \InvalidArgumentException if the class has no public view(Context) method
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
                $defaults[$name] = $default;

                continue;
            }

            if (self::getAttr($prop, Persist::class) instanceof Persist) {
                $persists[] = $name;
                $defaults[$name] = $default;
            }
        }

        // Collect #[Action], #[OnDisconnect], #[OnCleanup] methods
        $actions = [];
        $onDisconnect = null;
        $onCleanup = null;
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $methodName = $method->getName();

            $actionAttr = self::getMethodAttr($method, Action::class);
            if ($actionAttr instanceof Action) {
                $actions[] = [
                    'method' => $methodName,
                    'name'   => $actionAttr->name ?? $methodName,
                    'scope'  => $actionAttr->scope,
                ];
            }

            if (self::getMethodAttr($method, OnDisconnect::class) instanceof OnDisconnect) {
                if ($onDisconnect !== null) {
                    throw new \InvalidArgumentException(
                        "Class '{$class}' has more than one #[OnDisconnect] method."
                    );
                }
                $onDisconnect = $methodName;
            }

            if (self::getMethodAttr($method, OnCleanup::class) instanceof OnCleanup) {
                if ($onCleanup !== null) {
                    throw new \InvalidArgumentException(
                        "Class '{$class}' has more than one #[OnCleanup] method."
                    );
                }
                $onCleanup = $methodName;
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
            persists: $persists,
            actions: $actions,
            defaults: $defaults,
            viewRouteParams: $viewRouteParams,
            broadcastScope: $broadcastScope,
            onDisconnect: $onDisconnect,
            onCleanup: $onCleanup,
        );
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
