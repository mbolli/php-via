<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3010)
        ->withDevMode(true)
);

// Server-wide state (survives page navigations)
$app->setGlobalState('status', 'All systems operational');
$app->setGlobalState('visitors', 0);

// Per-route counters (static array, lives as long as the server)
$counters = ['/' => 0, '/page-a' => 0, '/page-b' => 0];

$pages = ['/' => 'Home', '/page-a' => 'Page A', '/page-b' => 'Page B'];

foreach ($pages as $path => $label) {
    $app->page($path, function (Context $c) use ($app, $label, $path, &$counters): void {
        // GLOBAL component — shared across ALL pages and users
        $globalBanner = $c->component(function (Context $c) use ($app): void {
            $c->scope(Scope::GLOBAL);

            $updateStatus = $c->action(function () use ($app): void {
                $statuses = ['All systems operational', 'Maintenance mode', 'High load detected', 'Everything is awesome!'];
                $app->setGlobalState('status', $statuses[array_rand($statuses)]);
                $visitors = $app->globalState('visitors', 0);
                $app->setGlobalState('visitors', $visitors + 1);
                $app->broadcast(Scope::GLOBAL);
            }, 'updateStatus');

            $c->view(function () use ($app, $updateStatus): string {
                $status = $app->globalState('status', 'Unknown');
                $visitors = $app->globalState('visitors', 0);

                return <<<HTML
                <div>
                    <div>Status: <strong>{$status}</strong> &middot; Visitors: <strong>{$visitors}</strong></div>
                    <button data-on:click="@get('{$updateStatus->url()}')">Update Status</button>
                </div>
                HTML;
            });
        }, 'global');

        // ROUTE component — shared by everyone on THIS page only
        $routeCounter = $c->component(function (Context $c) use ($app, $path, &$counters): void {
            $route = $c->getRoute();
            $c->scope(Scope::ROUTE);

            $increment = $c->action(function () use ($app, $route, &$counters): void {
                ++$counters[$route];
                $app->broadcast(Scope::ROUTE);
            }, 'increment');

            $reset = $c->action(function () use ($app, $route, &$counters): void {
                $counters[$route] = 0;
                $app->broadcast(Scope::ROUTE);
            }, 'reset');

            $c->view(function () use ($route, $increment, $reset, &$counters): string {
                $count = $counters[$route] ?? 0;

                return <<<HTML
                <div>
                    <div>Counter: <strong>{$count}</strong></div>
                    <button data-on:click="@get('{$increment->url()}')">+ Increment</button>
                    <button data-on:click="@get('{$reset->url()}')">Reset</button>
                </div>
                HTML;
            });
        }, 'route');

        // TAB component — private to each browser tab
        $tabMessage = $c->component(function (Context $c): void {
            $message = $c->signal('Hello from your personal tab!', 'personalMessage');

            $updateMessage = $c->action(function () use ($message, $c): void {
                $messages = ['You are awesome!', 'Having a great day?', 'Keep coding!', 'This is YOUR personal message!', 'Tab scope is cool!'];
                $message->setValue($messages[array_rand($messages)]);
                $c->syncSignals();
            }, 'updateMessage');

            $c->view(fn (): string => <<<HTML
                <div>
                    <input type="text" {$message->bind()}>
                    <div>Your message: <span data-text="\${$message->id()}"></span></div>
                    <button data-on:click="@post('{$updateMessage->url()}')">Random</button>
                </div>
                HTML);
        }, 'private');

        $c->view(function (bool $isUpdate) use ($c, $label, $globalBanner, $routeCounter, $tabMessage): string {
            $global = $c->component($globalBanner, 'global');
            $counter = $c->component($routeCounter, 'route');
            $tab = $c->component($tabMessage, 'private');

            if ($isUpdate) {
                return "{$global()}{$counter()}{$tab()}";
            }

            return sprintf(
                '<h1>%s</h1>'
                . '<nav><a href="/">Home</a> | <a href="/page-a">A</a> | <a href="/page-b">B</a></nav>'
                . '<h2>Global Scope</h2>%s'
                . '<h2>Route Scope</h2>%s'
                . '<h2>Tab Scope</h2>%s',
                $label,
                $global(),
                $counter(),
                $tab(),
            );
        });
    });
}

$app->start();
