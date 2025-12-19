# Changelog

All notable changes to php-via will be documented in this file.

## [1.0.0] - 2024-12-?

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
  - Example: `examples/path_params_injection.php` - Demonstrates automatic injection
  - 11 comprehensive tests verifying injection behavior

- **Path parameters support** - Dynamic route parameters inspired by go-via v0.1.4
  - Route patterns support `{param_name}` syntax (e.g., `/users/{id}`)
  - `Context::getPathParam(string $name)` - Retrieve parameter values from URL
  - Multiple parameters in single route (e.g., `/blog/{year}/{month}/{slug}`)
  - Mix of parameters and static segments (e.g., `/products/{id}/reviews`)
  - Example: `examples/path_params.php` - Comprehensive demonstration

### Scope System & Caching
- **Global scope** - App-wide state shared across all routes
  - `Context::globalAction()` - Register actions that affect global state
  - `Via::globalState()` / `Via::setGlobalState()` - Get/set global state values
  - `Via::broadcastGlobal()` - Broadcast to all contexts across all routes
  - Global view cache - Single render cached app-wide (maximum performance)
  - Automatic detection: uses only global actions = Global scope
  - 16 comprehensive tests covering all global scope scenarios
  - Example: `examples/global_notifications.php` - notification system across all pages

- **Automatic scope detection and caching** - Framework automatically detects whether a page uses global, route, or tab scope
  - Global scope: Pages using only global actions are cached app-wide
  - Route scope: Pages using only route actions are cached per-route (one render for all users on same route)
  - Tab scope: Pages using signals render fresh for each context (per-user state)
  - Scope detection is automatic based on signal/action usage patterns
  - Comprehensive test suite with 46 tests covering all scenarios (13 + 17 + 16)
  - See `src/Scope.php` for implementation details

### Real-time Features
- **`Context::interval()` method** - Execute functions periodically using Swoole timers
  - Takes milliseconds and callback function
  - Returns timer ID for potential cleanup
  - Example: `$c->interval(200, fn() => $c->sync())`

- **Action handler** - Supports both GET and POST parameters
  - Actions can receive data via `$_GET` or `$_POST`
  - Enables flexible action invocation patterns

### Examples
- `counter_basic.php` - Simple counter
- `counter.php` - Counter with step control  
- `greeter.php` - Form handling
- `components.php` - Component composition
- `todo.php` - Todo list
- `path_params.php` - Path parameter demonstration
- `path_params_injection.php` - Automatic parameter injection
- `global_notifications.php` - Global state management
- `game_of_life.php` - Multiplayer Conway's Game of Life
  - Demonstrates global state management pattern
  - Shows automatic Route scope detection and caching
  - Multiple users can draw simultaneously with different colors
  - Real-time synchronization across all connected clients

### Documentation
- `README.md` - Project overview and quick start
- `GETTING_STARTED.md` - Comprehensive tutorial
- `ARCHITECTURE.md` - Implementation details
- `TWIG_GUIDE.md` - Twig integration guide
- `TESTING.md` - Testing documentation
- `FUTURE_API_IDEAS.md` - Proposals for future API improvements

### Development Tools
- Composer scripts:
  - `composer phpstan` - Run PHPStan static analysis
  - `composer cs-fix` - Run PHP-CS-Fixer code formatter
