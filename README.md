# php-via

[![Latest Version on Packagist](https://img.shields.io/packagist/v/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![Total Downloads](https://img.shields.io/packagist/dt/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![License](https://img.shields.io/packagist/l/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![PHP Version](https://img.shields.io/packagist/php-v/mbolli/php-via.svg?style=flat-square)](https://packagist.org/packages/mbolli/php-via)
[![CI](https://img.shields.io/github/actions/workflow/status/mbolli/php-via/ci.yml?branch=master&style=flat-square&label=CI)](https://github.com/mbolli/php-via/actions)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%206-brightgreen.svg?style=flat-square)](https://phpstan.org/)
[![Docs](https://img.shields.io/badge/docs-via.zweiundeins.gmbh-blue?style=flat-square)](https://via.zweiundeins.gmbh)

<a href="https://via.zweiundeins.gmbh"><img src="https://raw.githubusercontent.com/mbolli/php-via/master/logo.png" alt="php-via"></a>

Real-time reactive web framework for PHP. Server-side reactive UIs with zero JavaScript, using [OpenSwoole](https://openswoole.com/) for async PHP, [Datastar](https://data-star.dev) for SSE + DOM morphing, and [Twig](https://twig.symfony.com/) for templating.

**[Documentation & Live Examples](https://via.zweiundeins.gmbh)**

## Why php-via?

- **No JavaScript to write**: Datastar handles client-side reactivity, SSE, and DOM morphing
- **Twig templates**: familiar, powerful server-side templating
- **No build step**: no transpilation, no bundling, no node_modules
- **Real-time by default**: every page gets a live SSE connection
- **Scoped state**: TAB, ROUTE, SESSION, GLOBAL, and custom scopes control who shares what
- **Single SSE stream**: extremely efficient with Brotli compression

## Requirements

- PHP 8.4+
- OpenSwoole PHP extension
- Composer
- Brotli PHP extension *(optional, required for `Config::withBrotli()`)*

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

    $c->action(function () use ($count, $step, $c): void {
        $count->setValue($count->int() + $step->int());
        $c->syncSignals();
    }, 'increment');

    $c->view('counter.html.twig');
});

$app->start();
```

**counter.html.twig:**

```twig
<div id="counter">
    <p>Count: <span data-text="${{ count.id }}">{{ count.int }}</span></p>
    <label>Step: <input type="number" data-bind="{{ step.id }}"></label>
    <button data-on:click="@post('{{ increment.url }}')">Increment</button>
</div>
```

```
php app.php
# → http://localhost:3000
```

## Core Concepts

Full documentation at **[via.zweiundeins.gmbh/docs](https://via.zweiundeins.gmbh/docs)**

### Signals: reactive state that syncs between server and client

```php
$name = $c->signal('Alice', 'name');
$name->string();          // read
$name->setValue('Bob');    // write → auto-pushes to browser
```

```twig
<input data-bind="{{ name.id }}">
<span data-text="${{ name.id }}">{{ name.string }}</span>
```

### Actions: server-side functions triggered by client events

```php
$save = $c->action(function () use ($c): void {
    $c->sync();
}, 'save');
```

```twig
<button data-on:click="@post('{{ save.url }}')">Save</button>
```

> **Important:** Always trigger actions with `@post()` (or `@patch`/`@put`/`@delete`).
> `@get()` is blocked on `/_action/…`: GET requests return **405 Method Not Allowed**,
> because allowing actions over GET enables top-level cross-site navigation CSRF.

### Scopes: control who shares state and receives broadcasts

| Scope | Sharing | Use Case |
|-------|---------|----------|
| `Scope::TAB` | Isolated per tab (default) | Personal forms, settings |
| `Scope::ROUTE` | All users on same route | Shared boards, multiplayer |
| `Scope::SESSION` | All tabs in same session | Cross-tab state |
| `Scope::GLOBAL` | All users everywhere | Notifications, announcements |
| Custom (`"room:lobby"`) | All contexts in that scope | Chat rooms, game lobbies |

### Views: Twig template files or inline strings

```php
$c->view('dashboard.html.twig', ['user' => $user]);
```

### Path Parameters: auto-injected by name

```php
$app->page('/blog/{year}/{slug}', function (Context $c, string $year, string $slug): void {
    // ...
});
```

### Components: reusable sub-contexts with isolated state

```php
$a = $c->component($counterWidget, 'a');
$b = $c->component($counterWidget, 'b');
```

### Lifecycle Hooks

```php
$c->onDisconnect(fn() => /* cleanup */);
$c->setInterval(fn() => $c->sync(), 2000);  // auto-cleaned on disconnect
$app->onClientConnect(fn(string $id) => /* ... */);
$app->setInterval(fn() => $app->broadcast(Scope::GLOBAL), 5000); // process-wide timer
```

### Route Groups: shared prefix and/or middleware

```php
$app->group('/admin', function (Via $app): void {
    $app->page('/', fn(Context $c) => ...);      // → /admin
    $app->page('/users', fn(Context $c) => ...); // → /admin/users
})->middleware(new AuthMiddleware());
```

### Broadcasting: push updates to other connected clients

```php
$c->broadcast();                    // same scope
$app->broadcast(Scope::GLOBAL);     // all contexts
$app->broadcast('room:lobby');      // custom scope
```

### Multi-node broadcasting: Redis and NATS brokers

By default php-via uses an `InMemoryBroker` that is correct for single-process deployments.
To fan out `broadcast()` calls across multiple servers or containers, swap in `RedisBroker` or
`NatsBroker`:

```php
use Mbolli\PhpVia\Broker\RedisBroker;
use Mbolli\PhpVia\Broker\NatsBroker;

// Redis (requires ext-redis + SWOOLE_HOOK_ALL)
$config->withBroker(new RedisBroker('127.0.0.1', 6379));

// Redis with auth and TLS
$config->withBroker(new RedisBroker(
    host: 'redis.internal',
    password: $_ENV['REDIS_PASSWORD'],
    tls: true,
));

// NATS (raw OpenSwoole socket — no extra extension)
$config->withBroker(new NatsBroker('127.0.0.1', 4222));

// NATS with token auth and TLS
$config->withBroker(new NatsBroker(
    host: 'nats.internal',
    authToken: $_ENV['NATS_TOKEN'],
    tls: true,
));

// Error observability — called on every connection drop
$config->onBrokerError(fn(\Throwable $e) => error_log('Broker: ' . $e->getMessage()));
```

Both brokers reconnect automatically with exponential backoff (1 s → 30 s cap).

A `GET /_health` endpoint is available on every php-via server (no configuration needed):

```json
{"status":"ok","version":"0.7.0","broker":{"driver":"RedisBroker","connected":true},"connections":{"contexts":42,"sse":38}}
```

Returns HTTP 503 when the broker is in the reconnect backoff window.

## How it Works

```
1. Browser requests page     →  Server renders HTML, opens SSE stream
2. User clicks button        →  Datastar POSTs signal values + action ID
3. Server executes action    →  Modifies signals / state
4. Server pushes patches     →  HTML fragments + signal updates via SSE
5. Datastar morphs DOM       →  UI updates without page reload
```

## Development

```bash
git clone https://github.com/mbolli/php-via.git
cd php-via && composer install

# Start website + hot PHP reload + CSS watcher (requires entr)
composer run dev

# Run tests
composer run test

# Watch tests on file change (requires entr)
composer run watch-test

# Static analysis and code style
composer phpstan
composer cs-fix
```

**Hot PHP reload**: edit a file in `website/src/`, the worker restarts automatically (~1 s) without dropping other connections. Twig templates are always live with no restart. See [docs/development](https://via.zweiundeins.gmbh/docs/development) for the full workflow and how to replicate this pattern in your own project.

## Credits

- [Datastar](https://data-star.dev/): SSE + DOM morphing
- [OpenSwoole](https://openswoole.com/): Async PHP
- [Twig](https://twig.symfony.com/): Templating
- [go-via/via](https://github.com/go-via/via): Original Go inspiration

## License

MIT
