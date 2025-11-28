<?php

declare(strict_types=1);
// Disable Xdebug for long-running SSE connections
ini_set('xdebug.mode', 'off');

require __DIR__ . '/../vendor/autoload.php';
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3000)
    ->withDocumentTitle('⚡ Via Counter Example')
    ->withDevMode(true)
    ->withLogLevel('debug')
    ->withTemplateDir(__DIR__ . '/../templates')
;

// Create the application
$app = new Via($config);

// Register the counter page
$app->page('/', function (Context $c): void {
    // Create reactive signals
    $count = $c->signal(0, 'count');
    $step = $c->signal(1, 'step');

    // Create an increment action
    $increment = $c->action(function () use ($count, $step, $c): void {
        $count->setValue($count->int() + $step->int());
        $c->syncSignals();
    }, 'increment');

    // Define the view using inline HTML
    $c->view(function () use (&$count, $step, $increment) {
        return <<<HTML
        <div class="page-layout" id="counter">
            <div class="container">
                <h1>⚡ Via Counter</h1>
                <p class="count">Count: <span data-text="\${$count->id()}"></span></p>
                <label>
                    Update Step:
                    <input type="number" {$step->bind()}>
                </label>
                <button data-on:click="@post('{$increment->url()}')">Increment</button>
            </div>
            <aside class="debug-sidebar">
                <h3>🔍 Live Signals</h3>
                <pre data-json-signals></pre>
            </aside>
        </div>
        <style>
            .page-layout { display: flex; gap: 20px; max-width: 1200px; margin: 50px auto; padding: 20px; font-family: system-ui, sans-serif; }
            .container { flex: 1; }
            .count { font-size: 2em; font-weight: bold; color: #333; margin: 20px 0; }
            input[type="number"] { padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; margin: 0 10px; }
            button { padding: 10px 20px; background: #0066cc; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; margin-top: 20px; }
            button:hover { background: #0052a3; }
            .debug-sidebar { width: 300px; background: #f5f5f5; padding: 20px; border-radius: 8px; border: 1px solid #ddd; }
            .debug-sidebar h3 { margin-top: 0; color: #333; font-size: 16px; }
            .debug-sidebar pre { background: #fff; padding: 15px; border-radius: 4px; border: 1px solid #ddd; overflow-x: auto; font-size: 12px; max-height: 500px; overflow-y: auto; }
        </style>
        HTML;
    });
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3000\n";
echo "Press Ctrl+C to stop\n";
$app->start();
