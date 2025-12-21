# Changelog

All notable changes to php-via will be documented in this file.

## [0.1.0] - 2025-12-21

Initial pre-release. API is not yet stable and may change in future versions.

### Core Features
- Via application class with Swoole HTTP server
- Context management for page state
- Reactive signals for state synchronization
- Action triggers for server-side event handling
- SSE (Server-Sent Events) support
- HTML composition helpers
- Component system for reusable UI
- Twig template integration

### Routing & Parameters
- **Automatic path parameter injection** - Route parameters automatically injected into callable parameters
  - Parameters matched by name from function signature: `function($c, string $username)`
  - No need to call `$c->getPathParam()` - params are automatically populated
  - Works with multiple parameters in any order
  - Supports default values and nullable parameters
  - Backward compatible: `$c->getPathParam()` still works
  - 7 comprehensive tests verifying injection behavior

- **Path parameters support** - Dynamic route parameters inspired by go-via v0.1.4
  - Route patterns support `{param_name}` syntax (e.g., `/users/{id}`)
  - `Context::getPathParam(string $name)` - Retrieve parameter values from URL
  - Multiple parameters in single route (e.g., `/blog/{year}/{month}/{slug}`)
  - Mix of parameters and static segments (e.g., `/products/{id}/reviews`)
  - Example: `examples/path_params.php` - Comprehensive demonstration

### Scope System & Caching
- **Global scope** - App-wide state shared across all routes
  - `Via::globalState()` / `Via::setGlobalState()` - Get/set global state values
  - `Via::broadcast(Scope::GLOBAL)` - Broadcast to all contexts across all routes
  - `Context::action($fn, $name, Scope::GLOBAL)` - Create actions with global scope
  - `Context::scope(Scope::GLOBAL)` - Set context to global scope
  - Global view cache - Single render cached app-wide (maximum performance)
  - Automatic detection: uses only global-scoped actions = Global scope
  - 15 comprehensive tests covering all scope scenarios
  - Example: `examples/global_notifications.php` - notification system across all pages

- **Automatic scope detection and caching** - Framework automatically detects whether a page uses global, route, or tab scope
  - Global scope: Pages using only global-scoped actions are cached app-wide
  - Route scope: Pages using only route-scoped actions are cached per-route (one render for all users on same route)
  - Tab scope: Pages with TAB-scoped signals/actions render fresh for each context (per-user state)
  - Scope detection is automatic based on signal/action scope patterns
  - 79 comprehensive tests covering all features
  - See `src/Scope.php` for scope constants and helpers

### Real-time Features
- **`Context::setInterval()` method** - Execute functions periodically using Swoole timers
  - Takes callback and milliseconds interval
  - Returns timer ID for potential cleanup
  - Automatically cleaned up when context is destroyed
  - Example: `$c->setInterval(fn() => $c->sync(), 200)`

- **Action handler** - Supports both GET and POST parameters
  - Actions can receive data via `$_GET` or `$_POST`
  - Enables flexible action invocation patterns

### Examples
- `counter_basic.php` - Simple counter
- `counter.php` - Counter with step control  
- `greeter.php` - Form handling
- `components.php` - Component composition
- `todo.php` - Todo list with local state
- `path_params.php` - Path parameter demonstration with automatic injection
- `global_notifications.php` - Global state and broadcasting across all routes
- `chat_room.php` - Multi-room chat with custom scopes
- `stock_ticker.php` - Real-time stock data with scoped state
- `client_monitor.php` - Monitor connected clients and contexts
- `profile_demo.php` - Interactive profile with intervals
- `all_scopes.php` - Demonstrates TAB, ROUTE, SESSION, and GLOBAL scopes
- `game_of_life.php` - Multiplayer Conway's Game of Life
  - Shows automatic Route scope detection and caching
  - Multiple users can draw simultaneously with different colors
  - Real-time synchronization across all connected clients

### Configuration & Infrastructure
- **BasePath support** - Serve applications under subpaths (e.g., `/myapp`)
  - `Config::withBasePath()` - Configure application base path
  - Automatic detection from request headers
  - Consistent resource loading across examples and templates
  - Navigation link updates for subpath deployment

- **onStart() callbacks** - Execute code when server starts
  - `Via::onStart()` - Register callbacks to run on server start
  - Useful for initialization, logging, or setup tasks

- **Swoole settings** - Configure Swoole HTTP server
  - `Config::withSwooleSettings()` and `getSwooleSettings()`
  - Customize server behavior and performance

- **HEAD method support** - Handle HEAD requests properly

### Template System
- **Twig @via namespace** - Register `@via` namespace for built-in templates
- **View caching** - Cache rendered templates for better performance
- **Shell template support** - Embed content in shell templates
- **Route-scoped signal option** - Create signals scoped to specific routes

### Resource Management
- **Cleanup callbacks** - Register cleanup functions on SSE disconnect
  - `Context::onCleanup()` / `Context::onDisconnect()` - Register callbacks for cleanup
  - `Context::setInterval()` - Timers are automatically cleaned up
  - Automatic cleanup of timers and resources when context is destroyed
- **Memory management** - Enhanced patch channel handling
  - Drop oldest patches when channel is full
  - Prevent memory leaks with proper resource cleanup

### Examples & Deployment
- `start-all.sh` - Script to run all examples simultaneously
- `examples/index.php` - Overview page for all examples
- Caddy configurations for production deployment
- Service files for systemd integration

### Development Tools
- Composer scripts:
  - `composer phpstan` - Run PHPStan static analysis
  - `composer cs-fix` - Run PHP-CS-Fixer code formatter
- Comprehensive test suite with Pest (79 tests, 230+ assertions)
- PHPStan level 6 compliance
