<?php

declare(strict_types=1);

use Mbolli\PhpVia\Via;

/*
 * The Dev Bar stream polled with a bare usleep(), which yields only under SWOOLE_HOOK_SLEEP. Without
 * that hook one open Dev Bar froze its worker: 0 of 3 health checks answered within 1.5 s each.
 *
 * Coroutine::run() in OpenSwoole 26.2 turns on SWOOLE_HOOK_ALL by itself when no flags are set, so
 * only a real server runs under the flags it is given.
 */

/** @return array<string, string> key => value from the fixture's key=value lines */
function devBarStreamServer(int $hookFlags, string $mode = ''): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/devbar_stream_server.php')
        . ' ' . $hookFlags . ($mode !== '' ? ' ' . escapeshellarg($mode) : '') . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);

    $values = [];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    expect($values)->toHaveKey('client_done', '1', 'fixture output: ' . var_export($out, true));

    return $values + ['out' => $out];
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('ext-pcntl and ext-posix required');
    }
});

test('an open Dev Bar stream leaves the worker answering', function (int $hookFlags): void {
    $r = devBarStreamServer($hookFlags);
    $context = $r['out'];

    expect((int) $r['health_ok'])->toBe(3, $context);
    expect((int) $r['health_max_ms'])->toBeLessThan(500, $context);
    expect((int) $r['page_status'])->toBe(200, $context);
    expect((int) $r['stream_traced'])->toBe(1, 'the stream did not deliver the page trace: ' . $context);
})->with([
    'narrow flags' => [Via::HOOK_FLAGS_NO_FILE_IO],
    'narrow flags without the sleep hook' => [Via::HOOK_FLAGS_NO_FILE_IO & ~SWOOLE_HOOK_SLEEP],
]);

test('the Dev Bar script is served with an ETag and revalidates to an empty 304', function (): void {
    $r = devBarStreamServer(Via::HOOK_FLAGS_NO_FILE_IO);
    $context = $r['out'];

    expect((int) $r['asset_status'])->toBe(200, $context);
    expect($r['asset_etag'])->toStartWith('W/"', $context);
    expect($r['asset_encoding'])->toBe('identity', $context);
    expect((int) $r['asset_match'])->toBe(1, $context);
    expect((int) $r['revalidate_status'])->toBe(304, $context);
    expect((int) $r['revalidate_bytes'])->toBe(0, $context);
});

test('the Dev Bar script is sent as Brotli when withBrotli() is on and the client accepts it', function (): void {
    if (!function_exists('brotli_uncompress')) {
        $this->markTestSkipped('ext-brotli required');
    }

    $r = devBarStreamServer(Via::HOOK_FLAGS_NO_FILE_IO, 'br');
    $context = $r['out'];

    expect((int) $r['asset_status'])->toBe(200, $context);
    expect($r['asset_encoding'])->toBe('br', $context);
    expect((int) $r['asset_match'])->toBe(1, 'the decompressed body differs from public/devbar.js: ' . $context);
    expect((int) $r['revalidate_status'])->toBe(304, $context);
});

test('start() refuses hook_flags that break the server before it binds a socket', function (string $settings, string $broker, string $message): void {
    $port = 0;
    for ($i = 0; $i < 30 && $port === 0; ++$i) {
        $candidate = 4300 + ((getmypid() + $i) % 30);
        $probe = @stream_socket_server("tcp://127.0.0.1:{$candidate}");
        if ($probe !== false) {
            fclose($probe);
            $port = $candidate;
        }
    }

    $code = 'require ' . var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true) . ';'
        . '$config = (new Mbolli\PhpVia\Config())->withHost("127.0.0.1")->withPort(' . $port . ')->withLogLevel("error")'
        . "->withSwooleSettings({$settings})->withBroker({$broker});"
        . 'try { (new Mbolli\PhpVia\Via($config))->start(); echo "started"; }'
        . ' catch (RuntimeException $e) { echo "refused: ", $e->getMessage(); }';
    $out = (string) shell_exec('timeout 20 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

    expect($out)->toStartWith('refused: ')->toContain($message);
})->with([
    'STDIO without FILE' => ["['hook_flags' => SWOOLE_HOOK_STDIO | SWOOLE_HOOK_TCP]", 'new Mbolli\PhpVia\Broker\InMemoryBroker()', 'SWOOLE_HOOK_STDIO without SWOOLE_HOOK_FILE'],
    'Redis without TCP' => ["['hook_flags' => SWOOLE_HOOK_SLEEP]", 'new Mbolli\PhpVia\Broker\RedisBroker()', 'RedisBroker needs SWOOLE_HOOK_TCP'],
]);
