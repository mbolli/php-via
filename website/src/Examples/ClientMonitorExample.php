<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class ClientMonitorExample
{
    public const string SLUG = 'client-monitor';

    private static string $routeScope = '';

    public static function register(Via $app): void
    {
        self::$routeScope = Scope::routeScope('/examples/client-monitor');

        $app->page('/examples/client-monitor', function (Context $c) use ($app): void {
            $c->scope(Scope::ROUTE);
            $c->view(function () use ($app, $c): string {
                $clients = $app->getClients();
                $clientCount = count($clients);

                $clientsHtml = '';
                foreach ($clients as $client) {
                    $duration = time() - $client['connected_at'];
                    $id = htmlspecialchars($client['id']);
                    $identicon = htmlspecialchars($client['identicon']);
                    $ip = htmlspecialchars($client['ip']);
                    $clientsHtml .= <<<HTML
                    <div class="card" style="min-width: 120px; text-align: center;">
                        <img src="{$identicon}" style="width: 64px; height: 64px; border-radius: var(--radius-md); margin-block-end: var(--size-1); display: block; margin-inline: auto;" />
                        <strong style="font-size: var(--font-size-0);">{$id}</strong><br>
                        <span style="font-size: 0.7rem; color: var(--text-3);">IP: {$ip}<br>Connected: {$duration}s ago</span>
                    </div>
                    HTML;
                }

                return $c->render('examples/client_monitor.html.twig', [
                    'title' => '👁️ Client Monitor',
                    'description' => 'Live dashboard of connected clients with identicons and IPs.',
                    'summary' => [
                        '<strong>Hook-driven updates</strong> — the client list re-renders only when someone connects or disconnects. No polling, no timer, no wasted cycles.',
                        '<strong>getClients()</strong> returns all active SSE connections with their identicon, IP, and connection duration. Open multiple tabs to see them appear.',
                        '<strong>onClientConnect / onClientDisconnect</strong> hooks fire globally. This example broadcasts to the monitor\'s ROUTE scope inside each hook.',
                        '<strong>Identicons</strong> give each connection a visual fingerprint. They\'re generated server-side from the session ID — same session always gets the same avatar.',
                        '<strong>ROUTE scope</strong> means every viewer of this page shares the same rendered output. The hook broadcasts once and all clients receive the same HTML patch.',
                        '<strong>Zero idle cost</strong> — unlike a timer, hooks fire only in response to real events. No guard needed to check for active viewers.',
                    ],
                    'sourceFile' => 'client_monitor.php',
                    'templateFiles' => ['client_monitor.html.twig'],
                    'clientCount' => $clientCount,
                    'clientsHtml' => $clientsHtml,
                ]);
            }, block: 'demo');
        });
    }

    public static function registerHooks(Via $app): void
    {
        $app->onClientConnect(function () use ($app): void {
            if ($app->getContextsByScope(self::$routeScope) !== []) {
                $app->broadcast(self::$routeScope);
            }
        });

        $app->onClientDisconnect(function () use ($app): void {
            if ($app->getContextsByScope(self::$routeScope) !== []) {
                $app->broadcast(self::$routeScope);
            }
        });
    }
}
