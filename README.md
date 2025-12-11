# 🚀 php-via

Real-time engine for building reactive web applications in PHP with Swoole.

Inspired by [go-via/via](https://github.com/go-via/via), this library brings the same reactive programming model to PHP using Swoole's async capabilities. [Datastar](https://data-star.dev) acts as the glue between server and client, handling DOM morphing and SSE communication.

## Why php-via?

- **Datastar-powered** - Reactive hypermedia framework handles client-side reactivity
- **Twig templates** - Familiar, powerful templating with Twig
- **No JavaScript** - Write server-side code only, Datastar handles the rest
- **No build step** - No transpilation or bundling
- **Full reactivity** - Real-time updates via SSE
- **Single SSE stream** - Efficient communication (pair with Caddy for Brotli: 2500 elements = ~0.2 KB)
- **Pure PHP** - Leveraging Swoole's coroutines

## Requirements

- PHP 8.1+
- Swoole extension
- Composer

## Installation

```bash
composer require mbolli/php-via
```

## Quick Start

```php
<?php

require 'vendor/autoload.php';

use Mbolli\PhpVia\Via;
use Mbolli\PhpVia\Config;

$config = new Config();
$config->withTemplateDir(__DIR__ . '/templates');

$app = new Via($config);

$app->page('/', function ($c) {
    $count = 0;
    $step = $c->signal(1);
    
    $increment = $c->action(function () use (&$count, $step, $c) {
        $count += $step->int();
        $c->sync();
    });
    
    $c->view(function () use (&$count, $step, $increment, $c) {
        return $c->renderString('
            <div id="counter"><!-- id is used to morph -->
                <p>Count: {{ count }}</p>
                <label>
                    Step: 
                    <input type="number" data-bind="{{ step.id }}">
                </label>
                <button data-on:click="@post(\'{{ increment.url() }}\')">
                    Increment
                </button>
            </div>
        ', [
            'count' => $count,
            'step' => $step,
            'increment' => $increment
        ]);
    });
});

$app->start();
```

Run the server:

```bash
php counter.php
```

Then open your browser to `http://localhost:3000`

## Core Concepts

### Via Application

The main application class that manages routing and the Swoole HTTP server.

```php
use Mbolli\PhpVia\Config;

$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3000)
    ->withDevMode(true)
    ->withLogLevel('debug');

$app = new Via($config);
```

### Context

A Context represents a living connection between the server and browser. Each page gets its own context.

```php
$app->page('/dashboard', function ($c) {
    // $c is the Context instance
    // Define signals, actions, and views here
});
```

### Signals

Signals are reactive values that sync between server and client automatically using Datastar's data model.

```php
$name = $c->signal('Alice');

// In your Twig template:
<input type="text" data-bind="{{ name.id }}">
<span data-text="${{ name.id }}"></span>

// Access the value in PHP:
$name->string()  // Get as string
$name->int()     // Get as integer
$name->bool()    // Get as boolean
$name->float()   // Get as float
```

### Actions

Actions are server-side functions triggered by client events via Datastar.

```php
$saveAction = $c->action(function () use ($c) {
    // Do something
    $c->sync();  // Push updates to browser
});

// In your Twig template:
<button data-on:click="@post('{{ saveAction.url }}')">Save</button>
```

### Views

Views can use Twig templates (inline or from files) or plain PHP functions.

**Inline Twig:**
```php
$c->view(function () use ($data, $c) {
    return $c->renderString('<h1>Hello, {{ name }}!</h1>', [
        'name' => $data->name
    ]);
});
```

### Datastar Attributes

php-via uses Datastar attributes for reactivity:

```twig
{# Two-way data binding with signals - use the bind() Twig function #}
<input type="text" {{ bind(nameSignal) }}>

{# Display signal value (note the $ sign to access the signal) #}
<span data-text="${{ nameSignal.id() }}"></span>

{# Trigger actions on events - actions use @get() #}
<button data-on:click="@get('{{ saveAction.url() }}')">Save</button>

{# Actions with specific keys #}
<input data-on:keydown.enter="@get('{{ submitAction.url() }}')">

{# Change events #}
<select data-on:change="@get('{{ updateAction.url() }}')">...</select>
```

See [Datastar documentation](https://data-star.dev/) for more attributes and patterns.

### Components

Components are reusable sub-contexts with their own state and actions:

```php
$counterComponent = function ($c) {
    $count = 0;
    
    $increment = $c->action(function () use (&$count, $c) {
        $count++;
        $c->sync();
    });
    
    $c->view(function () use (&$count, $increment, $c) {
        return $c->renderString('
            <div>
                <p>Count: {{ count }}</p>
                <button data-on:click="@post(\'{{ increment.url() }}\')">Increment</button>
            </div>
        ', [
            'count' => $count,
            'increment' => $increment
        ]);
    });
};

$app->page('/', function ($c) use ($counterComponent) {
    $counter1 = $c->component($counterComponent);
    $counter2 = $c->component($counterComponent);
    
    $c->view(function () use ($counter1, $counter2) {
        return <<<HTML
        <div>
            <h1>Counter 1</h1>
            {$counter1()}
            <h1>Counter 2</h1>
            {$counter2()}
        </div>
        HTML;
    });
});
```

## Architecture

php-via fundamentally relies on:

1. **Long-lived event loop** - Swoole provides coroutines and async I/O, similar to Go's goroutines
2. **Reactive state on the server** - Signals track changes and sync with the browser
3. **Server-side actions** - Client events trigger server-side PHP functions
4. **UI defined on the server** - Views are rendered with PHP, not templates
5. **Single SSE channel** - Efficient real-time communication via Server-Sent Events with **Brotli compression**
   - Even large updates (e.g., 2500 divs in Game of Life) compress to ~0.1-0.2 KB per update
   - Repetitive HTML structure compresses extremely well

### How it Works

1. **Initial Page Load**: Server renders HTML and establishes an SSE connection
2. **SSE Connection**: Browser maintains an open connection to receive live updates
3. **User Interaction**: User clicks button → Browser sends signal values + action ID
4. **Action Execution**: Server executes the action with current signal values
5. **Sync Changes**: Server pushes HTML patches and signal updates via SSE
6. **DOM Merge**: Datastar merges the patches into the DOM reactively

### Technology Stack

- **Swoole**: Provides async I/O, coroutines, and HTTP server
- **Datastar**: The glue between server and client - handles SSE communication, DOM merging, and reactive data binding
- **Twig**: Server-side templating with Datastar attributes
- **Server-Sent Events**: Unidirectional stream from server to browser for real-time updates

## Examples

Check the `examples/` directory for more examples:

- `counter_basic.php` - Simple counter with reactive signals
- `counter.php` - Counter with step control and components
- `greeter.php` - Form handling with multiple inputs
- `components.php` - Reusable component patterns
- `todo.php` - Todo list (multiplayer)
- `game_of_life.php` - Conway's Game of Life with real-time updates (multiplayer)

## Credits

This library is inspired by and builds upon:

- 🚀 [go-via/via](https://github.com/go-via/via) - The original Go implementation
- 🌟 [Datastar](https://data-star.dev/) - The reactive hypermedia framework
- 🐘 [Swoole](https://www.swoole.co.uk/) - PHP async/coroutine framework

## License

MIT

## Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues.

## Development

```bash
# Clone the repository
git clone https://github.com/mbolli/php-via.git
cd php-via

# Install dependencies
composer install

# Run the counter example
php examples/counter.php

# Code quality tools
composer phpstan        # Run static analysis
composer cs-fix         # Fix code style
```

## Roadmap

- [x] Core Via class with routing
- [x] Context management
- [x] Reactive signals
- [x] Action triggers
- [x] SSE support
- [ ] Component system improvements
- [ ] Session management
- [ ] More examples
- [ ] Testing suite
- [ ] Documentation site

## Stay Reactive! ⚡
