<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Timer;

$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3010)
    ->withDevMode(true)
    ->withLogLevel('info')
;

$app = new Via($config);

// Start broadcast timer when server starts (not when clients connect)
$app->onStart(function () use ($app): void {
    echo "Starting broadcast timer...\n";
    Timer::tick(2000, function () use ($app): void {
        $app->broadcast(Scope::ROUTE);
    });
});

$app->page('/', function (Context $ctx) use ($app): void {
    $ctx->scope(Scope::ROUTE);
    $ctx->view(function () use ($app) {
        $clients = $app->getClients();
        $stats = $app->getRenderStats();
        $clientCount = count($clients);

        $clientsHtml = '';
        foreach ($clients as $client) {
            $duration = time() - $client['connected_at'];
            $id = htmlspecialchars($client['id']);
            $identicon = htmlspecialchars($client['identicon']);
            $ip = htmlspecialchars($client['ip']);

            $clientsHtml .= <<<HTML
            <div class="card" style="min-width: 150px; background-color: var(--color-dark); color: white;">
                <img src="{$identicon}" style="width: 100px; height: 100px; border-radius: var(--border-radius); margin-bottom: 8px; display: block;" />
                <strong style="font-size: 16px;">{$id}</strong><br>
                <div style="font-size: 12px; line-height: 1.6; color: #ddd;">
                    IP: {$ip}<br>
                    Connected: {$duration}s ago
                </div>
            </div>
            HTML;
        }

        $renderCount = number_format($stats['render_count']);
        $totalTime = number_format($stats['total_time'] * 1000, 2);
        $avgTime = number_format($stats['avg_time'] * 1000, 3);
        $minTime = number_format($stats['min_time'] * 1000, 3);
        $maxTime = number_format($stats['max_time'] * 1000, 3);

        return <<<HTML
        <div class="container" id="content">
            <h1>👥 Client Monitor</h1>

            <div class="card">
                <h2>Connected Clients ({$clientCount})</h2>
                <div class="flex gap-3" style="flex-wrap: wrap;">
                    {$clientsHtml}
                </div>
            </div>

            <div class="card">
                <h2>Render Statistics</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><td style="padding: 4px;"><strong>Total Renders:</strong></td><td>{$renderCount}</td></tr>
                    <tr><td style="padding: 4px;"><strong>Total Time:</strong></td><td>{$totalTime} ms</td></tr>
                    <tr><td style="padding: 4px;"><strong>Average Time:</strong></td><td>{$avgTime} ms</td></tr>
                    <tr><td style="padding: 4px;"><strong>Min Time:</strong></td><td>{$minTime} ms</td></tr>
                    <tr><td style="padding: 4px;"><strong>Max Time:</strong></td><td>{$maxTime} ms</td></tr>
                </table>
            </div>
        </div>
        HTML;
    });
});

echo "Starting Client Monitor on http://localhost:3010\n";
echo "Stats JSON endpoint: http://localhost:3010/_stats\n";

$app->start();
