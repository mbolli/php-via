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
    ->withPort(3001)
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
            <div class="container" id="content">
                <h1>⚡ Via Counter</h1>
                <div class="card">
                    <p class="count">Count: <span data-text="\${$count->id()}"></span></p>
                    <label>Update Step: <input type="number" {$step->bind()}></label>
                    <button data-on:click="@post('{$increment->url()}')">Increment</button>
                </div>
            </div>
        HTML;
    });
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3001\n";
echo "Press Ctrl+C to stop\n";
$app->start();
