<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Composition;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Attributes\StateApp;
use Mbolli\PhpVia\Attributes\StateSess;
use Mbolli\PhpVia\Attributes\StateTab;
use Mbolli\PhpVia\Context;

/**
 * Reflection metadata for a page/component class.
 *
 * Analyzed once per class and cached statically.
 */
final class ClassMetadata {
    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string> $signals
     * @param array<string> $stateTabs
     * @param array<string> $stateSessions
     * @param array<string> $stateApps
     * @param array<array{method: string, name: string, scope: ?string}> $actions
     * @param array<string, mixed> $defaults
     * @param array<array{name: string, type: string}> $viewRouteParams
     */
    private function __construct(
        public readonly string $class,
        /** Property names annotated #[Signal] */
        public readonly array $signals,
        /** Property names annotated #[StateTab] (documentation-only, no Signal created) */
        public readonly array $stateTabs,
        /** Property names annotated #[StateSess] */
        public readonly array $stateSessions,
        /** Property names annotated #[StateApp] */
        public readonly array $stateApps,
        /**
         * Actions: [['method' => string, 'name' => string, 'scope' => ?string], …].
         *
         * @var array<array{method: string, name: string, scope: ?string}>
         */
        public readonly array $actions,
        /** Default value for each annotated property */
        public readonly array $defaults,
        /**
         * Route params declared on view() beyond the first Context param.
         * Each entry: ['name' => string, 'type' => string].
         *
         * @var array<array{name: string, type: string}>
         */
        public readonly array $viewRouteParams,
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
        $stateTabs = [];
        $stateSessions = [];
        $stateApps = [];
        $defaults = [];

        foreach ($rc->getProperties() as $prop) {
            if (!$prop->isPublic() && !self::hasAnyReactiveAttribute($prop)) {
                continue;
            }

            $name = $prop->getName();

            if (self::getAttr($prop, Signal::class) !== null) {
                $signals[] = $name;
                $defaults[$name] = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;
            } elseif (self::getAttr($prop, StateTab::class) !== null) {
                $stateTabs[] = $name;
                $defaults[$name] = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;
            } elseif (self::getAttr($prop, StateSess::class) !== null) {
                $stateSessions[] = $name;
                $defaults[$name] = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;
            } elseif (self::getAttr($prop, StateApp::class) !== null) {
                $stateApps[] = $name;
                $defaults[$name] = $prop->hasDefaultValue() ? $prop->getDefaultValue() : null;
            }
        }

        // Collect #[Action] methods
        $actions = [];
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attr = self::getMethodAttr($method, Action::class);
            if (!$attr instanceof Action) {
                continue;
            }
            $actionName = $attr->name ?? $method->getName();
            $actions[] = [
                'method' => $method->getName(),
                'name'   => $actionName,
                'scope'  => $attr->scope,
            ];
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
            stateTabs: $stateTabs,
            stateSessions: $stateSessions,
            stateApps: $stateApps,
            actions: $actions,
            defaults: $defaults,
            viewRouteParams: $viewRouteParams,
        );
    }

    private static function hasAnyReactiveAttribute(\ReflectionProperty $prop): bool {
        foreach ([Signal::class, StateTab::class, StateSess::class, StateApp::class] as $attrClass) {
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
