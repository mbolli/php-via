<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3008)
        ->withDevMode(true)
);

// Broadcast when clients connect or disconnect — no timer needed
$app->onClientConnect(function (Context $c) use ($app): void {
    $app->broadcast(Scope::ROUTE);
});
$app->onClientDisconnect(function (Context $c) use ($app): void {
    $app->broadcast(Scope::ROUTE);
});

$app->page('/', function (Context $c) use ($app): void {
    $c->scope(Scope::ROUTE);

    $c->view(function () use ($app) {
        $clients = $app->getClients();

        $html = '<h1>Connected: ' . count($clients) . '</h1>';

        foreach ($clients as $client) {
            $duration = time() - $client['connected_at'];
            $html .= sprintf(
                '<div class="card"><img src="%s" width="64" height="64" />'
                . '<strong>%s</strong> — %s — %ds ago</div>',
                htmlspecialchars($client['identicon']),
                htmlspecialchars($client['id']),
                htmlspecialchars($client['ip']),
                $duration,
            );
        }

        return '<div id="content">' . $html . '</div>';
    });
});

$app->start();
