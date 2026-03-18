# php-via

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![Total Downloads](https://img.shields.io/packagist/dt/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![License](https://img.shields.io/packagist/l/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![PHP Version](https://img.shields.io/packagist/php-v/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![CI](https://img.shields.io/github/actions/workflow/status/mbolli/php-via/ci.yml?branch=master&style=flat-square&label=CI)](https://github.com/mbolli/php-via/actions)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![Docs](https://img.shields.io/badge/docs-via.zweiundeins.gmbh-blue?style=flat-square)](https://via.zweiundeins.gmbh)

<a href="https://via.zweiundeins.gmbh"><img src="https://raw.githubusercontent.com/mbolli/php-via/master/logo.png" alt="php-via"></a>

Real-time reactive web framework for PHP. Server-side reactive UIs with zero JavaScript, using [OpenSwoole](https://openswoole.com/) for async PHP, [Datastar](https://data-star.dev) (RC.8) for SSE + DOM morphing, and [Twig](https://twig.symfony.com/) for templating.

**[Documentation & Live Examples](https://via.zweiundeins.gmbh)**

## Why php-via?

- **No JavaScript to write** — Datastar handles client-side reactivity, SSE, and DOM morphing
- **Twig templates** — familiar, powerful server-side templating
- **No build step** — no transpilation, no bundling, no node_modules
- **Real-time by default** — every page gets a live SSE connection
- **Scoped state** — TAB, ROUTE, SESSION, GLOBAL, and custom scopes control who shares what
- **Single SSE stream** — extremely efficient with Brotli compression

## Requirements

- PHP 8.4+
- OpenSwoole extension
- Composer

## Installation

```
composer require mbolli/php-via
```

## Quick Start

```php
<?php
require 'vendor/autoload.php';

use Mbolli\PhpVia\Via;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;

$config = new Config();
$config->withTemplateDir(__DIR__ . '/templates');
$app = new Via($config);

$app->page('/', function (Context $c): void {
    $count = $c->signal(0, 'count');
    $step  = $c->signal(1, 'step');

    $increment = $c->action(function () use ($count, $step, $c): void {
        $count->setValue($count->int() + $step->int());
        $c->syncSignals();
    }, 'increment');

    $c->view('counter.html.twig', [
        'count'     => $count,
        'step'      => $step,
        'increment' => $increment,
    ]);
});

$app->start();
```

**counter.html.twig:**

```twig
<div id="counter">
    <p>Count: <span data-text="${{ count.id }}">{{ count.int }}</span></p>
    <label>Step: <input type="number" data-bind="{{ step.id }}"></label>
    <button data-on-click="@post('{{ increment.url }}')">Increment</button>
</div>
```

```
php app.php
# → http://localhost:3000
```

## Core Concepts

Full documentation at **[via.zweiundeins.gmbh/docs](https://via.zweiundeins.gmbh/docs)**

### Signals — reactive state that syncs between server and client

```php
$name = $c->signal('Alice', 'name');
$name->string();          // read
$name->setValue('Bob');    // write → auto-pushes to browser
```

```twig
<input data-bind="{{ name.id }}">
<span data-text="${{ name.id }}">{{ name.string }}</span>
```

### Actions — server-side functions triggered by client events

```php
$save = $c->action(function () use ($c): void {
    $c->sync();
}, 'save');
```

```twig
<button data-on-click="@post('{{ save.url }}')">Save</button>
```

### Scopes — control who shares state and receives broadcasts

| Scope | Sharing | Use Case |
|-------|---------|----------|
| `Scope::TAB` | Isolated per tab (default) | Personal forms, settings |
| `Scope::ROUTE` | All users on same route | Shared boards, multiplayer |
| `Scope::SESSION` | All tabs in same session | Cross-tab state |
| `Scope::GLOBAL` | All users everywhere | Notifications, announcements |
| Custom (`"room:lobby"`) | All contexts in that scope | Chat rooms, game lobbies |

### Views — Twig template files or inline strings

```php
$c->view('dashboard.html.twig', ['user' => $user]);
```

### Path Parameters — auto-injected by name

```php
$app->page('/blog/{year}/{slug}', function (Context $c, string $year, string $slug): void {
    // ...
});
```

### Components — reusable sub-contexts with isolated state

```php
$a = $c->component($counterWidget, 'a');
$b = $c->component($counterWidget, 'b');
```

### Lifecycle Hooks

```php
$c->onDisconnect(fn() => /* cleanup */);
$c->setInterval(fn() => $c->sync(), 2000);  // auto-cleaned on disconnect
$app->onClientConnect(fn(string $id) => /* ... */);
```

### Broadcasting — push updates to other connected clients

```php
$c->broadcast();                    // same scope
$app->broadcast(Scope::GLOBAL);     // all contexts
$app->broadcast('room:lobby');      // custom scope
```

## How it Works

```
1. Browser requests page     →  Server renders HTML, opens SSE stream
2. User clicks button        →  Datastar POSTs signal values + action ID
3. Server executes action    →  Modifies signals / state
4. Server pushes patches     →  HTML fragments + signal updates via SSE
5. Datastar morphs DOM       →  UI updates without page reload
```

## Examples

**[Live Examples](https://via.zweiundeins.gmbh/examples)** — All run inside the website with source code

| Example | Scope | Description |
|---------|-------|-------------|
| [Counter](https://via.zweiundeins.gmbh/examples/counter) | TAB | Reactive signals with configurable step and data-bind |
| [Greeter](https://via.zweiundeins.gmbh/examples/greeter) | TAB | Form inputs and multiple actions |
| [Todo](https://via.zweiundeins.gmbh/examples/todo) | ROUTE | Multiplayer shared todo list |
| [Components](https://via.zweiundeins.gmbh/examples/components) | TAB | Three independent counters as isolated components |
| [Path Params](https://via.zweiundeins.gmbh/examples/path-params) | TAB | Dynamic routing with auto-injected parameters |
| [Stock Ticker](https://via.zweiundeins.gmbh/examples/stock-ticker) | Custom | Real-time prices with ECharts, timers, per-stock scopes |
| [Chat Room](https://via.zweiundeins.gmbh/examples/chat-room) | Custom | Multi-room chat with typing indicators and session usernames |
| [Client Monitor](https://via.zweiundeins.gmbh/examples/client-monitor) | ROUTE | Live dashboard of connected clients with identicons |
| [Game of Life](https://via.zweiundeins.gmbh/examples/game-of-life) | ROUTE | Multiplayer Conway's Game of Life with colored cells |
| [All Scopes](https://via.zweiundeins.gmbh/examples/all-scopes) | Mixed | TAB, ROUTE, and GLOBAL scopes side by side |

## Development

```
git clone https://github.com/mbolli/php-via.git
cd php-via && composer install

cd website && php app.php    # run website + examples on :3000

vendor/bin/pest              # 85 tests, 240 assertions
composer phpstan             # PHPStan level 6
composer cs-fix              # code style
```

## Deployment

Single OpenSwoole process behind a reverse proxy. See [deploy/](deploy/) for systemd + Caddy configs.

```
Browser → Caddy (TLS + Brotli) → OpenSwoole :3000
```

## Roadmap

- [x] Core — Via, Context, Signals, Actions, SSE, Twig
- [x] Scopes — TAB, ROUTE, SESSION, GLOBAL + custom with wildcards
- [x] Path parameters with reflection-based auto-injection
- [x] Components with isolated sub-contexts
- [x] View caching per scope
- [x] Broadcasting with scope-based targeting
- [x] Lifecycle hooks — `onDisconnect`, `onCleanup`, `setInterval`
- [x] App hooks — `onClientConnect`, `onClientDisconnect`, `onStart`, `onShutdown`
- [x] Global state, per-context shell/head/foot overrides
- [x] 85 tests (Pest), PHPStan level 6, PHP-CS-Fixer
- [x] 11 examples + docs website
- [x] Production deployment (systemd + Caddy)
- [ ] Session management (persistent across reconnects)
- [ ] Middleware system
- [ ] Route groups
- [ ] Form validation helpers

## Credits

- [Datastar](https://data-star.dev/) — SSE + DOM morphing
- [OpenSwoole](https://openswoole.com/) — Async PHP
- [Twig](https://twig.symfony.com/) — Templating
- [go-via/via](https://github.com/go-via/via) — Original Go inspiration

## License

MIT
