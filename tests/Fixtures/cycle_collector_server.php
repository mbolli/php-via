<?php

declare(strict_types=1);

/*
 * Fixture for CycleCollectorTest: a real server whose /garbage route leaves 48 MiB in a few hundred reference cycles,
 * far fewer possible roots than PHP's collector waits for. A forked client asks /gc for the worker's collector state
 * before and after.
 *
 * argv[1] = the withGcIntervalMs() value
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$intervalMs = (int) ($argv[1] ?? 30_000);

try {
    $port = FixturePort::pick(4330, 20);
} catch (RuntimeException $e) {
    echo "fixture_error=no_free_port\n";

    exit(1);
}

/** @return array<string, mixed> the decoded JSON body of GET $path, empty when it fails */
function fetchJson(int $port, string $path): array {
    $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, stream_context_create(['http' => ['timeout' => 5]]));

    return is_string($body) ? (array) json_decode($body, true) : [];
}

$master = getmypid();
$pid = pcntl_fork();
if ($pid === -1) {
    echo "fixture_error=fork_failed\n";

    exit(1);
}

if ($pid === 0) {
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && fetchJson($port, '/gc') === []) {
        usleep(50_000);
    }

    $before = fetchJson($port, '/gc');
    $garbage = fetchJson($port, '/garbage');
    usleep(500_000);
    $after = fetchJson($port, '/gc');

    echo 'enabled=', (int) ($before['enabled'] ?? -1), "\n";
    echo 'garbage_mib=', $garbage['mib'] ?? -1, "\n";
    echo 'runs_since=', ($after['runs'] ?? 0) - ($garbage['runs'] ?? 0), "\n";
    echo 'freed_mib=', round((($garbage['memory'] ?? 0) - ($after['memory'] ?? 0)) / 1048576), "\n";
    echo "client_done=1\n";
    posix_kill($master, SIGTERM);

    exit(0);
}

$config = (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withGcIntervalMs($intervalMs);
$app = new Via($config);

$app->route('GET', '/gc', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'enabled' => gc_enabled(),
            'runs' => gc_status()['runs'],
            'memory' => memory_get_usage(),
        ]));
    }
});

$app->route('GET', '/garbage', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        for ($i = 0; $i < 192; ++$i) {
            $a = new stdClass();
            $b = new stdClass();
            $a->peer = $b;
            $b->peer = $a;
            $a->payload = str_repeat((string) $i, 256 << 10);
        }
        unset($a, $b);

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'mib' => 48,
            'runs' => gc_status()['runs'],
            'memory' => memory_get_usage(),
        ]));
    }
});

$app->start();
pcntl_waitpid($pid, $status);
