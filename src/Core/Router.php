<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Context;

/**
 * Router - Route registration and matching.
 *
 * Manages:
 * - Route registration
 * - Path matching with parameter extraction
 * - Handler invocation with parameter injection
 */
class Router {
    /** @var array<string, callable> */
    private array $routes = [];

    /**
     * Register a page route with its handler.
     *
     * @param string   $route   The route pattern (e.g., '/')
     * @param callable $handler Function that receives a Context instance
     */
    public function registerRoute(string $route, callable $handler): void {
        $this->routes[$route] = $handler;
    }

    /**
     * Get all registered routes.
     *
     * @return array<string, callable>
     */
    public function getRoutes(): array {
        return $this->routes;
    }

    /**
     * Find a matching route for the given path.
     *
     * @param string                $path   Request path
     * @param array<string, string> $params Output array for extracted parameters
     *
     * @return null|callable Handler if route matches, null otherwise
     */
    public function matchRoute(string $path, array &$params = []): ?callable {
        foreach ($this->routes as $route => $handler) {
            if ($this->isRouteMatch($route, $path, $params)) {
                return $handler;
            }
        }

        return null;
    }

    /**
     * Invoke a handler with automatic path parameter injection.
     *
     * Inspects the callable's parameters and automatically injects path parameters
     * matching the parameter names, along with the Context as the first parameter.
     * Automatically casts route parameters to the expected type (int, float, bool, string).
     *
     * @param callable              $handler     Route handler
     * @param Context               $context     Context instance
     * @param array<string, string> $routeParams Available route parameters
     */
    public function invokeHandler(callable $handler, Context $context, array $routeParams): void {
        try {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
        } catch (\ReflectionException $e) {
            // Fallback: just call with context
            $handler($context);

            return;
        }

        $parameters = $reflection->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            // First parameter should be Context (or if it's type-hinted as Context)
            if ($paramType instanceof \ReflectionNamedType && $paramType->getName() === Context::class) {
                $args[] = $context;

                continue;
            }

            // Check if this parameter name matches a route parameter
            if (isset($routeParams[$paramName])) {
                $value = $routeParams[$paramName];

                // Cast to the expected type if type hint is present
                if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                    // Non-builtin type, pass as string
                    $args[] = $value;
                } elseif ($paramType instanceof \ReflectionNamedType) {
                    $args[] = $this->castToType($value, $paramType->getName());
                } else {
                    // No type hint, pass as string
                    $args[] = $value;
                }

                continue;
            }

            // If parameter has default value, use it
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            // If parameter is optional (nullable), pass null
            if ($param->allowsNull()) {
                $args[] = null;

                continue;
            }

            // Otherwise, pass empty string for missing parameters
            $args[] = '';
        }

        $handler(...$args);
    }

    /**
     * Match route pattern against path and extract parameters.
     *
     * @param string                $route  Route pattern (e.g., '/users/{id}')
     * @param string                $path   Request path (e.g., '/users/123')
     * @param array<string, string> $params Output array for extracted parameters
     *
     * @return bool True if route matches
     */
    private function isRouteMatch(string $route, string $path, array &$params = []): bool {
        // Exact match (no parameters)
        if ($route === $path) {
            return true;
        }

        // Check if route has parameters
        if (!str_contains($route, '{')) {
            return false;
        }

        // Convert route pattern to regex
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_]\w*)\}/',
            static fn (array $matches) => '(?P<' . $matches[1] . '>[^/]+)',
            $route
        );
        $pattern = '#^' . $pattern . '$#';

        // Match and extract parameters
        if (preg_match($pattern, $path, $matches)) {
            // Extract named parameters
            foreach ($matches as $key => $value) {
                if (\is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Cast a string value to the specified type.
     *
     * @param string $value The string value to cast
     * @param string $type  The target type (int, float, bool, string)
     *
     * @return mixed The casted value
     */
    private function castToType(string $value, string $type): mixed {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
