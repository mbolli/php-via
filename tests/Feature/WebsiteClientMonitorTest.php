<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Examples\ClientMonitorExample;

/*
 * Every visitor of the website's Client Monitor sees the whole list, so it shows each client's
 * network, never the full IP address.
 */

$monitorAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$monitorReady = false;

if (is_file($monitorAutoload)) {
    require_once $monitorAutoload;
    $monitorReady = class_exists('PhpVia\\Website\\Examples\\ClientMonitorExample');
}

if (!$monitorReady) {
    test('website client monitor (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

function clientMonitorApp(): TestApp {
    return new TestApp(
        (new Config())->withLogLevel('error')->withTemplateDir(dirname(__DIR__, 2) . '/website/templates'),
        static function (Via $via): void {
            foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
                $via->getTwig()->addGlobal($name, $value);
            }
            ClientMonitorExample::register($via);
            ClientMonitorExample::registerHooks($via);
        },
    );
}

function clientMonitorNetwork(string $ip): string {
    return (new ReflectionMethod(ClientMonitorExample::class, 'network'))->invoke(null, $ip);
}

test('the monitor shows a connected client by its network, not its IP address', function (): void {
    $app = clientMonitorApp();
    $tab = $app->open('/examples/client-monitor');

    // TestRequest connects from 127.0.0.1.
    expect($app->via()->getClients())->not->toBeEmpty();
    $html = $tab->html();
    expect($html)->toContain('IP: 127.0.0.0/16')
        ->and($html)->not->toContain('127.0.0.1')
        ->and($html)->toContain('IP addresses show only their network')
    ;

    $app->shutdown();
});

test('IPv4 keeps two octets, IPv6 keeps 32 bits, and anything else shows as unknown', function (string $ip, string $shown): void {
    expect(clientMonitorNetwork($ip))->toBe($shown);
})->with([
    'IPv4' => ['203.0.113.57', '203.0.0.0/16'],
    'IPv6' => ['2001:db8:85a3::8a2e:370:7334', '2001:db8::/32'],
    'IPv4-mapped IPv6' => ['::ffff:198.51.100.7', '198.51.0.0/16'],
    'unknown' => ['unknown', 'unknown'],
    'a forged X-Forwarded-For' => ['<b>hacked</b>', 'unknown'],
    'two addresses' => ['10.0.0.1, 192.0.2.4', 'unknown'],
]);
