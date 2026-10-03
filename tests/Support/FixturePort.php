<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Ports for the fixtures that start a real server.
 *
 * Each fixture has a window of its own by default. With VIA_TEST_PORT_BASE set, every fixture takes its port from
 * VIA_TEST_PORT_COUNT ports (default 50) starting there instead, so the suite can run on a machine where only a
 * range of ports is free:
 *
 *     VIA_TEST_PORT_BASE=4350 VIA_TEST_PORT_COUNT=40 vendor/bin/pest
 */
final class FixturePort {
    public const int DEFAULT_COUNT = 50;

    /**
     * A port nothing listens on right now, from [$base, $base + $count) or the configured window. Probed, because a
     * server with enable_reuse_port would share a busy port without an error. The start depends on the process ID,
     * so two fixtures that start together are unlikely to try the same port first.
     *
     * @throws \RuntimeException when every port in the window is taken
     */
    public static function pick(int $base, int $count): int {
        [$base, $count] = self::window($base, $count);
        $offset = getmypid() % $count;
        // PHPUnit reports a warning silenced with @, and a busy port is no warning here.
        set_error_handler(static fn (): bool => true);

        try {
            for ($i = 0; $i < $count; ++$i) {
                $port = $base + ($offset + $i) % $count;
                $probe = stream_socket_server("tcp://127.0.0.1:{$port}");
                if ($probe !== false) {
                    fclose($probe);

                    return $port;
                }
            }
        } finally {
            restore_error_handler();
        }

        throw new \RuntimeException(\sprintf('No free port in %d-%d for a test fixture', $base, $base + $count - 1));
    }

    /**
     * The window a fixture takes its port from: its own, or the one VIA_TEST_PORT_BASE and VIA_TEST_PORT_COUNT set.
     *
     * @return array{int, int} first port and number of ports
     */
    public static function window(int $base, int $count): array {
        $configured = getenv('VIA_TEST_PORT_BASE');
        if ($configured === false || $configured === '') {
            return [$base, $count];
        }
        if (!ctype_digit($configured) || (int) $configured < 1 || (int) $configured > 65535) {
            throw new \RuntimeException("VIA_TEST_PORT_BASE must be a port number, got '{$configured}'");
        }
        $configuredCount = getenv('VIA_TEST_PORT_COUNT');
        $count = $configuredCount !== false && $configuredCount !== '' ? (int) $configuredCount : self::DEFAULT_COUNT;

        return [(int) $configured, max(1, min($count, 65536 - (int) $configured))];
    }
}
