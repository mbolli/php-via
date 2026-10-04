<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class ClientMonitorExample {
    public const string SLUG = 'client-monitor';

    private static string $routeScope = '';

    public static function register(Via $app): void {
        self::$routeScope = Scope::routeScope('/examples/client-monitor');

        $app->page('/examples/client-monitor', function (Context $c) use ($app): void {
            $c->scope(Scope::ROUTE);
            $c->view('examples/client_monitor.html.twig', fn (): array => [
                'title' => 'Client Monitor',
                'description' => 'Live dashboard of connected clients, with identicons and masked IP addresses.',
                'summary' => [
                    '<strong>Hook-driven updates</strong>: the client list re-renders only when someone connects or disconnects. No polling, no timer, no wasted cycles.',
                    '<strong>getClients()</strong> returns every open SSE stream, on every worker, with its identicon, IP address, and connection time. Open multiple tabs to see them appear.',
                    '<strong>Masked IP addresses</strong>: every visitor sees this list, so it shows only the network of each address: the first two octets of an IPv4 address, the first 32 bits of an IPv6 address. <code>getClients()</code> itself returns the full address.',
                    '<strong>onClientConnect / onClientDisconnect</strong> hooks fire globally. This example broadcasts to the monitor\'s ROUTE scope inside each hook.',
                    '<strong>Identicons</strong> give each connection a visual fingerprint. The server draws them from a random id each tab gets when its stream first connects.',
                    '<strong>ROUTE scope</strong> means every viewer of this page shares the same rendered output. The hook broadcasts once and all clients receive the same HTML patch.',
                    '<strong>Zero idle cost</strong>: unlike a timer, hooks fire only in response to real events, and <code>countClients()</code> skips the broadcast while nobody has this page open on any worker.',
                ],
                'anatomy' => [
                    'signals' => [],
                    'actions' => [],
                    'views' => [
                        ['name' => 'client_monitor.html.twig', 'desc' => 'Re-renders on connect/disconnect hooks via ROUTE broadcast. Shows identicons, masked IP addresses, and connection duration.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ClientMonitorExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/client_monitor.html.twig'],
                ],
                'clients' => array_map(static fn (array $client): array => [
                    'id' => $client['id'],
                    'identicon' => $client['identicon'],
                    'connected_at' => $client['connected_at'],
                    'network' => self::network($client['ip']),
                ], array_values($app->getClients())),
                'now' => time(),
            ], block: 'demo', shareRender: true);
        });
    }

    public static function registerHooks(Via $app): void {
        $app->onClientConnect(function () use ($app): void {
            if ($app->countClients(self::$routeScope) > 0) {
                $app->broadcast(self::$routeScope);
            }
        });

        $app->onClientDisconnect(function () use ($app): void {
            if ($app->countClients(self::$routeScope) > 0) {
                $app->broadcast(self::$routeScope);
            }
        });
    }

    /**
     * The network of an address, as the page shows it: 203.0.0.0/16 for IPv4, 2001:db8::/32 for IPv6.
     */
    private static function network(string $ip): string {
        $packed = filter_var($ip, FILTER_VALIDATE_IP) !== false ? inet_pton($ip) : false;
        if ($packed === false) {
            // A proxy that passes on the client's own X-Forwarded-For lets it send any text here.
            return 'unknown';
        }
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12); // IPv4-mapped IPv6, as a dual-stack listener reports IPv4 clients
        }

        return \strlen($packed) === 4
            ? inet_ntop(substr($packed, 0, 2) . "\0\0") . '/16'
            : inet_ntop(substr($packed, 0, 4) . str_repeat("\0", 12)) . '/32';
    }
}
