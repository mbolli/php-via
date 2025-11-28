<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Create configuration
$config = new Config();
$config->withDocumentTitle('⚡ Via Counter - Twig Example')
    ->withLogLevel('debug')
    ->withTemplateDir(__DIR__ . '/../templates')
;

// Create the application
$app = new Via($config);

// Register the counter page using a Twig template
$app->page('/', function (Context $c): void {
    // Initialize data
    $count = 0;

    // Create a reactive signal for the step value
    $step = $c->signal(1);

    // Create an increment action
    $increment = $c->action(function () use (&$count, $step, $c): void {
        $count += $step->int();
        $c->sync();
    });

    // Render view with inline template
    $c->view(function () use (&$count, $step, $increment, $c) {
        return $c->renderString(<<<'TWIG'
            <div class="container" id="counter">
                <h1>⚡ Via Counter</h1>
                <p class="count">Count: {{ count }}</p>
                <label>
                    Update Step:
                    <input type="number" {{ bind(step) }}>
                </label>
                <button data-on:click="@get('{{ increment.url() }}')">Increment</button>
            </div>
            <style>
                .container { max-width: 600px; margin: 50px auto; padding: 20px; font-family: system-ui, sans-serif; }
                .count { font-size: 2em; font-weight: bold; color: #333; margin: 20px 0; }
                input[type="number"] { padding: 8px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; margin: 0 10px; }
                button { padding: 10px 20px; background: #0066cc; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer; margin-top: 20px; }
                button:hover { background: #0052a3; }
            </style>
        TWIG, [
            'count' => $count,
            'step' => $step,
            'increment' => $increment,
        ]);
    });
});

echo "Starting Via server with Twig on http://0.0.0.0:3000\n";
echo "Press Ctrl+C to stop\n";
$app->start();
