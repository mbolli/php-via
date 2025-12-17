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
    ->withPort(3003)
    ->withLogLevel('info')
    ->withTemplateDir(__DIR__ . '/../templates')
;

// Create the application
$app = new Via($config);

// Register the greeter page
$app->page('/', function (Context $c): void {
    // Create a reactive signal for the greeting
    $greeting = $c->signal('Hello...', 'greeting');

    // Create action to greet Bob
    $greetBob = $c->action(function () use ($greeting, $c): void {
        $greeting->setValue('Hello Bob!');
        $c->syncSignals();
    }, 'greetBob');

    // Create action to greet Alice
    $greetAlice = $c->action(function () use ($greeting, $c): void {
        $greeting->setValue('Hello Alice!');
        $c->syncSignals();
    }, 'greetAlice');

    // Use Twig template
    $c->view('greeter.html.twig', [
        'greeting' => $greeting,
        'greet_bob' => $greetBob,
        'greet_alice' => $greetAlice,
    ]);
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3003\n";
echo "Press Ctrl+C to stop\n";
$app->start();
