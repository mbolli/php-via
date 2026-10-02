<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\RedisBroker;
use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/*
 * The hook_flags Via starts with, the narrow set it names, and the boot guard against flag sets that
 * break the server: STDIO without FILE makes include yield halfway through a file, and a RedisBroker
 * without the hook for its transport blocks the whole worker on every call.
 */

describe('hook flag sets', function (): void {
    test('the default is SWOOLE_HOOK_ALL, without the native curl hook where it crashes, and reaches the server settings', function (): void {
        $expected = Via::nativeCurlHookCrashes() ? SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_NATIVE_CURL : SWOOLE_HOOK_ALL;

        expect(Via::defaultHookFlags())->toBe($expected);
        expect(Via::serverSettings(new Config())['hook_flags'])->toBe(Via::defaultHookFlags());
    });

    test('the narrow set hooks sockets, sleep and proc_open, and no file or stdio I/O and no native curl', function (): void {
        $expected = SWOOLE_HOOK_TCP | SWOOLE_HOOK_UDP | SWOOLE_HOOK_UNIX | SWOOLE_HOOK_UDG | SWOOLE_HOOK_SSL
            | SWOOLE_HOOK_TLS | SWOOLE_HOOK_STREAM_FUNCTION | SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_PROC;

        expect(Via::HOOK_FLAGS_NO_FILE_IO)->toBe($expected);
        expect(Via::HOOK_FLAGS_NO_FILE_IO & SWOOLE_HOOK_FILE)->toBe(0);
        expect(Via::HOOK_FLAGS_NO_FILE_IO & SWOOLE_HOOK_STDIO)->toBe(0);
        expect(Via::HOOK_FLAGS_NO_FILE_IO & SWOOLE_HOOK_NATIVE_CURL)->toBe(0);
        // Everything else it hooks is part of SWOOLE_HOOK_ALL.
        expect(Via::HOOK_FLAGS_NO_FILE_IO & ~SWOOLE_HOOK_ALL)->toBe(0);
    });

    test('the narrow set is 1790 on OpenSwoole 26.2', function (): void {
        if (!str_starts_with((string) phpversion('openswoole'), '26.2.')) {
            $this->markTestSkipped('bit values checked against OpenSwoole 26.2');
        }

        expect(Via::HOOK_FLAGS_NO_FILE_IO)->toBe(1790);
        expect(SWOOLE_HOOK_ALL)->toBe(2147457023);
    });

    test('withSwooleSettings() overrides the default', function (): void {
        $config = (new Config())->withSwooleSettings(['hook_flags' => Via::HOOK_FLAGS_NO_FILE_IO]);

        expect(Via::serverSettings($config)['hook_flags'])->toBe(Via::HOOK_FLAGS_NO_FILE_IO);
    });
});

describe('Via::assertHookFlags()', function (): void {
    test('accepts the default, the narrow set and STDIO together with FILE', function (int $flags): void {
        Via::assertHookFlags(['hook_flags' => $flags], new InMemoryBroker());
        Via::assertHookFlags(['hook_flags' => $flags], new SwooleBroker());

        expect(true)->toBeTrue();
    })->with([
        'default' => [Via::defaultHookFlags()],
        'narrow' => [Via::HOOK_FLAGS_NO_FILE_IO],
        'FILE and STDIO' => [SWOOLE_HOOK_FILE | SWOOLE_HOOK_STDIO],
        'none' => [0],
    ]);

    test('refuses STDIO without FILE', function (int $flags): void {
        expect(fn () => Via::assertHookFlags(['hook_flags' => $flags], new InMemoryBroker()))
            ->toThrow(RuntimeException::class, 'SWOOLE_HOOK_STDIO without SWOOLE_HOOK_FILE')
        ;
    })->with([
        'STDIO alone' => [SWOOLE_HOOK_STDIO],
        'the default minus FILE' => [Via::defaultHookFlags() & ~SWOOLE_HOOK_FILE],
        'the narrow set plus STDIO' => [Via::HOOK_FLAGS_NO_FILE_IO | SWOOLE_HOOK_STDIO],
    ]);

    test('accepts a RedisBroker under the default and the narrow set', function (RedisBroker $broker): void {
        Via::assertHookFlags(['hook_flags' => Via::defaultHookFlags()], $broker);
        Via::assertHookFlags(['hook_flags' => Via::HOOK_FLAGS_NO_FILE_IO], $broker);

        expect(true)->toBeTrue();
    })->with([
        'tcp' => [new RedisBroker()],
        'tls' => [new RedisBroker('redis.internal', 6380, tls: true)],
        'socket path' => [new RedisBroker('/var/run/redis/redis.sock', 0)],
    ]);

    test('refuses a RedisBroker without the hook its transport needs', function (RedisBroker $broker, int $hook, string $name): void {
        expect($broker->requiredHookFlag())->toBe($hook);

        expect(fn () => Via::assertHookFlags(['hook_flags' => Via::defaultHookFlags() & ~$hook], $broker))
            ->toThrow(RuntimeException::class, "RedisBroker needs {$name} in hook_flags")
        ;
    })->with([
        'tcp' => [new RedisBroker(), SWOOLE_HOOK_TCP, 'SWOOLE_HOOK_TCP'],
        'tls' => [new RedisBroker('redis.internal', 6380, tls: true), SWOOLE_HOOK_TLS, 'SWOOLE_HOOK_TLS'],
        'socket path' => [new RedisBroker('/var/run/redis/redis.sock', 0), SWOOLE_HOOK_UNIX, 'SWOOLE_HOOK_UNIX'],
        'unix:// socket' => [new RedisBroker('unix:///var/run/redis/redis.sock', 0), SWOOLE_HOOK_UNIX, 'SWOOLE_HOOK_UNIX'],
    ]);

    test('treats missing hook_flags as none', function (): void {
        expect(fn () => Via::assertHookFlags([], new RedisBroker()))
            ->toThrow(RuntimeException::class, 'RedisBroker needs SWOOLE_HOOK_TCP')
        ;
    });
});
