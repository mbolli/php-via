<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Broker;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Client;

/**
 * NATS Core pub/sub broker for multi-node broadcasting.
 *
 * Uses a single TCP connection (NATS supports both publish and subscribe on
 * one connection, unlike Redis). Implements the minimal NATS text protocol
 * needed for Via: INFO/CONNECT handshake, PUB, SUB, MSG dispatch.
 *
 * Adapted from website/src/Support/NatsClient.php — stripped to the subset
 * required for broker use only. The library does not depend on website code.
 *
 * What crosses the wire: JSON {"scope":"...","nodeId":"..."} on the subject
 * "via.broadcast". State is NOT carried — receiving nodes re-render locally.
 *
 * Usage:
 *   (new Config())->withBroker(new NatsBroker())
 *   (new Config())->withBroker(new NatsBroker('nats-host', 4222))
 *
 * Requirements:
 *   - NATS server running (see NATS_INSTALL.md)
 *   - Must be started from within a coroutine context (Via workerStart)
 *
 * Note: Uses Core NATS (fire-and-forget). Messages published before this
 * node subscribes are lost. Use NATS JetStream for durable delivery.
 */
final class NatsBroker implements MessageBroker {
    private const string SUBJECT = 'via.broadcast';

    private readonly string $nodeId;

    private ?Client $client = null;
    private string $buf = '';
    private bool $running = false;
    private int $subSid = 1;

    /** @var callable(string $scope): void|null */
    private $handler = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 4222,
    ) {
        $this->nodeId = bin2hex(random_bytes(8));
    }

    public function connect(): void {
        $this->client = new Client(SWOOLE_SOCK_TCP);
        if (!$this->client->connect($this->host, $this->port, 3.0)) {
            throw new \RuntimeException(
                "NatsBroker: cannot connect to NATS at {$this->host}:{$this->port}: {$this->client->errMsg}"
            );
        }

        $this->readLine(); // Discard INFO line.
        $this->send('CONNECT ' . json_encode([
            'verbose' => false,
            'pedantic' => false,
            'name' => 'php-via-broker-' . $this->nodeId,
        ]) . "\r\n");

        // Subscribe to the broadcast subject.
        $this->send("SUB " . self::SUBJECT . " {$this->subSid}\r\n");

        $this->running = true;
        $this->startReadLoop();
    }

    public function disconnect(): void {
        $this->running = false;

        if ($this->client !== null) {
            $this->client->close();
            $this->client = null;
        }
    }

    public function publish(string $scope): void {
        if ($this->client === null) {
            return;
        }

        $payload = json_encode(['scope' => $scope, 'nodeId' => $this->nodeId]);
        $bytes = \strlen($payload);
        $this->send("PUB " . self::SUBJECT . " {$bytes}\r\n{$payload}\r\n");
    }

    public function subscribe(callable $handler): void {
        $this->handler = $handler;
    }

    public function getNodeId(): string {
        return $this->nodeId;
    }

    private function send(string $data): void {
        $this->client?->send($data);
    }

    /**
     * Read one CRLF-terminated line from the NATS connection.
     * Uses internal buffer to handle partial TCP reads.
     */
    private function readLine(): ?string {
        while (true) {
            $pos = strpos($this->buf, "\r\n");

            if ($pos !== false) {
                $line = substr($this->buf, 0, $pos);
                $this->buf = substr($this->buf, $pos + 2);

                return $line;
            }

            $chunk = $this->client?->recv(4096);

            if ($chunk === false || $chunk === '') {
                return null; // Connection closed.
            }

            $this->buf .= $chunk;
        }
    }

    /**
     * Read exactly $bytes bytes, respecting the internal buffer.
     *
     * readLine() may consume extra bytes from the TCP stream into $this->buf.
     * This method drains from $this->buf before reading from the socket,
     * preventing bytes from being lost when frames arrive in large chunks.
     *
     * @return string the bytes read, or a shorter string on connection close
     */
    private function readBytes(int $bytes): string {
        $result = '';

        // Drain from buffer first.
        if ($this->buf !== '') {
            $take = min($bytes, \strlen($this->buf));
            $result = substr($this->buf, 0, $take);
            $this->buf = substr($this->buf, $take);
            $bytes -= $take;
        }

        // Read remainder from socket.
        while ($bytes > 0) {
            $chunk = $this->client?->recv($bytes);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $result .= $chunk;
            $bytes -= \strlen($chunk);
        }

        return $result;
    }

    /**
     * Spawn a dedicated read-loop coroutine that dispatches incoming NATS frames.
     */
    private function startReadLoop(): void {
        Coroutine::create(function (): void {
            while ($this->running && $this->client !== null) {
                $line = $this->readLine();

                if ($line === null) {
                    break; // Connection closed.
                }

                if ($line === 'PING') {
                    $this->send("PONG\r\n");
                    continue;
                }

                // MSG <subject> <sid> <bytes>
                if (str_starts_with($line, 'MSG ')) {
                    $parts = explode(' ', $line);
                    $bytes = (int) ($parts[3] ?? $parts[2] ?? 0);

                    $payload = $this->readBytes($bytes);

                    // Consume trailing \r\n after payload.
                    $this->readBytes(2);

                    $data = json_decode($payload, true);

                    if (!\is_array($data) || !isset($data['scope'], $data['nodeId'])) {
                        continue;
                    }

                    if ($data['nodeId'] === $this->nodeId) {
                        continue; // Skip own messages — loop prevention.
                    }

                    if ($this->handler !== null) {
                        ($this->handler)($data['scope']);
                    }
                }
            }
        });
    }
}
