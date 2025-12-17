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
    ->withPort(3005)
    ->withTemplateDir(__DIR__ . '/../templates')
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

// Define a reusable counter component
$counterComponent = function (Context $c): void {
    // Use a signal to store the count
    $count = $c->signal(0, 'count');

    $increment = $c->action(function () use ($count, $c): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $decrement = $c->action(function () use ($count, $c): void {
        $count->setValue($count->int() - 1);
        $c->sync();
    }, 'decrement');

    // Use Twig template for the component
    $c->view('component_counter.html.twig', [
        'namespace' => $c->getNamespace(),
        'count' => $count,
        'increment' => $increment,
        'decrement' => $decrement,
    ]);
};

// Register the main page
$app->page('/', function (Context $c) use ($counterComponent): void {
    // Create three independent counter components with namespaces
    $counter1 = $c->component($counterComponent, 'counter1');
    $counter2 = $c->component($counterComponent, 'counter2');
    $counter3 = $c->component($counterComponent, 'counter3');

    // Use Twig template
    $c->view('components.html.twig', [
        'counter1' => $counter1(),
        'counter2' => $counter2(),
        'counter3' => $counter3(),
    ]);
});

// Start the server
echo "Starting Via server on http://0.0.0.0:3005\n";
echo "Press Ctrl+C to stop\n";
$app->start();
