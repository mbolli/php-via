<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Swoole\Timer;

$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3000)
    ->withDevMode(true)
    ->withLogLevel('info')
;

$app = new Via($config);

// Global timer - render once and broadcast to all clients
static $timerStarted = false;
if (!$timerStarted) {
    Timer::tick(2000, function () use ($app): void {
        $app->broadcast('/');
    });
    $timerStarted = true;
}

$app->page('/', function (Context $ctx) use ($app): void {
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
            <div style="padding: 10px; background: white; border-radius: 4px; border: 2px solid #ddd;">
                <img src="{$identicon}" style="width: 100px; height: 100px; border-radius: 4px;" />
                <div style="margin-top: 5px; font-size: 12px;">
                    <strong>{$id}</strong><br>
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
        <div id="profiling" style="font-family: sans-serif; padding: 20px;">
            <h1>Via Profiling Demo</h1>

            <div style="margin: 20px 0; padding: 15px; background: #f0f0f0; border-radius: 8px;">
                <h2>Connected Clients ({$clientCount})</h2>
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    {$clientsHtml}
                </div>
            </div>

            <div style="margin: 20px 0; padding: 15px; background: #e8f4f8; border-radius: 8px;">
                <h2>Render Statistics</h2>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><td style="padding: 5px;"><strong>Total Renders:</strong></td><td>{$renderCount}</td></tr>
                    <tr><td style="padding: 5px;"><strong>Total Time:</strong></td><td>{$totalTime} ms</td></tr>
                    <tr><td style="padding: 5px;"><strong>Average Time:</strong></td><td>{$avgTime} ms</td></tr>
                    <tr><td style="padding: 5px;"><strong>Min Time:</strong></td><td>{$minTime} ms</td></tr>
                    <tr><td style="padding: 5px;"><strong>Max Time:</strong></td><td>{$maxTime} ms</td></tr>
                </table>
            </div>

            <p style="color: #666; font-size: 14px;">Stats available at <a href="/_stats" target="_blank">/_stats</a> (JSON)</p>
        </div>
        HTML;
    });
});

echo "Starting profiling demo on http://localhost:3002\n";
echo "Stats JSON endpoint: http://localhost:3002/_stats\n";

$app->start();
