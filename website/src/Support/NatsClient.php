<?php

declare(strict_types=1);

namespace PhpVia\Website\Support;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Client;

/**
 * Minimal OpenSwoole-native NATS client.
 *
 * Uses Coroutine\Client (non-blocking TCP) so it integrates seamlessly with
 * OpenSwoole's coroutine scheduler — no event-loop conflict.
 *
 * Supports: Core pub/sub, JetStream stream management, JetStream push consumers
 * (for replay), and JetStream-backed KV buckets.
 */
final class NatsClient {
    private Client $client;

    /** @var array<int, callable(string, string): void> sid => (subject, payload) */
    private array $handlers = [];

    private int $nextSid = 1;

    private string $buf = '';

    private bool $running = false;

    /** @var array<int, true> Subscription IDs that should auto-ACK via JetStream reply-to. */
    private array $jsSids = [];

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 4222,
    ) {}

    /**
     * Connect to NATS and perform the INFO/CONNECT handshake.
     *
     * @throws \RuntimeException if the TCP connection fails
     */
    public function connect(): void {
        $this->client = new Client(SWOOLE_SOCK_TCP);

        if (!$this->client->connect($this->host, $this->port, 3.0)) {
            throw new \RuntimeException("Cannot connect to NATS at {$this->host}:{$this->port}: {$this->client->errMsg}");
        }

        $this->readLine(); // discard INFO line

        $this->send(
            'CONNECT ' . json_encode(['verbose' => false, 'pedantic' => false, 'name' => 'php-via']) . "\r\n"
        );
    }

    /**
     * Spawn a dedicated read-loop coroutine that dispatches incoming frames.
     * Must be called from within a coroutine context.
     */
    public function startReadLoop(): void {
        $this->running = true;

        Coroutine::create(function (): void {
            while ($this->running) {
                $line = $this->readLine();
                if ($line === null) {
                    $this->running = false;

                    break;
                }
                $this->dispatch($line);
            }
        });
    }

    /**
     * Subscribe to a subject. Returns the subscription ID.
     *
     * @param callable(string, string): void $handler Called with (subject, payload)
     */
    public function subscribe(string $subject, callable $handler): int {
        $sid = $this->nextSid++;
        $this->handlers[$sid] = $handler;
        $this->send("SUB {$subject} {$sid}\r\n");

        return $sid;
    }

    /**
     * Subscribe to a JetStream push consumer delivery subject with automatic ACK.
     * Every MSG/HMSG that carries a reply-to address will be ACK'd automatically.
     * Handlers still receive the original NATS subject (from Nats-Subject header when available).
     *
     * @param callable(string, string): void $handler Called with (subject, payload)
     */
    public function subscribeJs(string $subject, callable $handler): int {
        $sid = $this->nextSid++;
        $this->handlers[$sid] = $handler;
        $this->jsSids[$sid] = true;
        $this->send("SUB {$subject} {$sid}\r\n");

        return $sid;
    }

    /** Unsubscribe a previously created subscription. */
    public function unsubscribe(int $sid): void {
        $this->send("UNSUB {$sid}\r\n");
        unset($this->handlers[$sid], $this->jsSids[$sid]);
    }

    /** Publish a message to a subject, optionally with a reply-to inbox. */
    public function publish(string $subject, string $payload, ?string $replyTo = null): void {
        $bytes = \strlen($payload);
        $reply = $replyTo !== null ? " {$replyTo}" : '';
        $this->send("PUB {$subject}{$reply} {$bytes}\r\n{$payload}\r\n");
    }

    /**
     * Send a request and wait for the first reply (inbox pattern).
     * Used for JetStream API calls ($JS.API.*).
     *
     * Returns the reply payload, or null on timeout.
     */
    public function request(string $subject, string $payload, float $timeout = 3.0): ?string {
        $inbox = '_INBOX.' . bin2hex(random_bytes(8));
        $ch = new Channel(1);

        $sid = $this->subscribe($inbox, static function (string $sub, string $data) use ($ch): void {
            $ch->push($data, 0.0);
        });

        // Auto-unsub after first reply
        $this->send("UNSUB {$sid} 1\r\n");
        $this->publish($subject, $payload, $inbox);

        $result = $ch->pop($timeout);
        unset($this->handlers[$sid]);

        return $result === false ? null : (string) $result;
    }

    /**
     * Ensure a JetStream stream exists (creates it if not present).
     *
     * @param string $name          Stream name (uppercase, no spaces)
     * @param string $subjectFilter e.g. "via.events.>"
     */
    public function ensureStream(string $name, string $subjectFilter, int $maxMsgs = 1000): void {
        $existing = $this->request("\$JS.API.STREAM.INFO.{$name}", '');
        if ($existing !== null) {
            $info = json_decode($existing, true);
            if (!\is_array($info) || !isset($info['error'])) {
                return; // already exists
            }
        }

        $this->request(
            "\$JS.API.STREAM.CREATE.{$name}",
            (string) json_encode([
                'name' => $name,
                'subjects' => [$subjectFilter],
                'retention' => 'limits',
                'max_msgs' => $maxMsgs,
                'storage' => 'memory',
                'num_replicas' => 1,
            ]),
        );
    }

    /**
     * Ensure a JetStream-backed KV bucket exists (creates it if not present).
     *
     * @param string $bucket KV bucket name (no spaces)
     */
    public function ensureKvBucket(string $bucket): void {
        $streamName = "KV_{$bucket}";
        $existing = $this->request("\$JS.API.STREAM.INFO.{$streamName}", '');

        if ($existing !== null) {
            $info = json_decode($existing, true);
            if (!\is_array($info) || !isset($info['error'])) {
                return;
            }
        }

        $this->request(
            "\$JS.API.STREAM.CREATE.{$streamName}",
            (string) json_encode([
                'name' => $streamName,
                'subjects' => ["\$KV.{$bucket}.>"],
                'max_msgs_per_subject' => 1,
                'storage' => 'memory',
                'num_replicas' => 1,
                'allow_direct' => true,
            ]),
        );
    }

    /**
     * Ensure a named durable JetStream push consumer exists.
     * Creates it with `deliver_policy: new` and explicit ACK so missed messages
     * (consumer offline) are redelivered after ack_wait.
     *
     * @param int $ackWaitNs ACK wait in nanoseconds (default 8 s)
     */
    public function ensureDurableConsumer(
        string $stream,
        string $durableName,
        string $filterSubject,
        string $deliverSubject,
        int $ackWaitNs = 8_000_000_000,
    ): void {
        $existing = $this->request("\$JS.API.CONSUMER.INFO.{$stream}.{$durableName}", '');

        if ($existing !== null) {
            $info = json_decode($existing, true);
            if (\is_array($info) && !isset($info['error'])) {
                return; // already exists
            }
        }

        $this->request(
            "\$JS.API.CONSUMER.DURABLE.CREATE.{$stream}.{$durableName}",
            (string) json_encode([
                'stream_name' => $stream,
                'config' => [
                    'durable_name' => $durableName,
                    'deliver_subject' => $deliverSubject,
                    'deliver_policy' => 'new',
                    'ack_policy' => 'explicit',
                    'ack_wait' => $ackWaitNs,
                    'max_deliver' => -1,
                    'filter_subject' => $filterSubject,
                ],
            ]),
        );
    }

    /**
     * Fetch the last N messages from a JetStream stream using an ephemeral
     * push consumer. Blocks the calling coroutine for up to ~800ms.
     *
     * @return list<string> Raw payloads (newest-last order)
     */
    public function fetchStreamLast(string $stream, string $subjectFilter, int $maxMsgs): array {
        // Get last sequence from stream info
        $infoJson = $this->request("\$JS.API.STREAM.INFO.{$stream}", '');
        if ($infoJson === null) {
            return [];
        }

        $info = json_decode($infoJson, true);
        if (!\is_array($info) || isset($info['error'])) {
            return [];
        }

        $lastSeq = (int) ($info['state']['last_seq'] ?? 0);
        if ($lastSeq === 0) {
            return [];
        }

        $startSeq = max(1, $lastSeq - $maxMsgs + 1);
        $inbox = '_INBOX.fetch.' . bin2hex(random_bytes(8));

        // Subscribe BEFORE creating the consumer to avoid missing messages
        // delivered in the gap between consumer creation and subscription.
        $messages = [];
        $ch = new Channel($maxMsgs + 2);

        $sid = $this->subscribe($inbox, static function (string $sub, string $data) use ($ch): void {
            $ch->push($data, 0.0);
        });

        // Create ephemeral push consumer starting at $startSeq
        $consumerJson = $this->request(
            "\$JS.API.CONSUMER.CREATE.{$stream}",
            (string) json_encode([
                'config' => [
                    'deliver_subject' => $inbox,
                    'deliver_policy' => 'by_start_sequence',
                    'opt_start_seq' => $startSeq,
                    'ack_policy' => 'none',
                    'max_deliver' => 1,
                    'filter_subject' => $subjectFilter,
                ],
            ]),
        );

        if ($consumerJson === null) {
            $this->unsubscribe($sid);

            return [];
        }

        $consumer = json_decode($consumerJson, true);
        $consumerName = \is_array($consumer) ? ($consumer['name'] ?? null) : null;

        if (\is_array($consumer) && isset($consumer['error'])) {
            $this->unsubscribe($sid);

            return [];
        }

        $deadline = microtime(true) + 0.8;

        while (\count($messages) < $maxMsgs && microtime(true) < $deadline) {
            $msg = $ch->pop(max(0.05, $deadline - microtime(true)));
            if ($msg === false) {
                break;
            }
            $messages[] = (string) $msg;
        }

        $this->unsubscribe($sid);

        // Clean up the ephemeral consumer
        if (\is_string($consumerName)) {
            $this->request("\$JS.API.CONSUMER.DELETE.{$stream}.{$consumerName}", '');
        }

        return $messages;
    }

    /** Close the connection and stop the read loop. */
    public function close(): void {
        $this->running = false;
        $this->client->close();
    }

    // ── Internal I/O ──────────────────────────────────────────────────────────

    /**
     * Read bytes from the buffer, blocking the coroutine until enough arrive.
     * Returns empty string if the connection closes before $n bytes are available.
     */
    private function readBytes(int $n): string {
        while (\strlen($this->buf) < $n) {
            $chunk = $this->client->recv(-1);
            if ($chunk === '' || $chunk === false) {
                return '';
            }
            $this->buf .= $chunk;
        }

        $data = substr($this->buf, 0, $n);
        $this->buf = substr($this->buf, $n);

        return $data;
    }

    /**
     * Read a CRLF-terminated line from the buffer (without the trailing \r\n).
     * Returns null if the connection closes.
     */
    private function readLine(): ?string {
        while (true) {
            $pos = strpos($this->buf, "\r\n");
            if ($pos !== false) {
                $line = substr($this->buf, 0, $pos);
                $this->buf = substr($this->buf, $pos + 2);

                return $line;
            }

            $chunk = $this->client->recv(-1);
            if ($chunk === '' || $chunk === false) {
                return null;
            }

            $this->buf .= $chunk;
        }
    }

    private function send(string $data): void {
        $this->client->send($data);
    }

    /** Extract the Nats-Subject header value from a JetStream HMSG header block. */
    private function extractNatsSubject(string $headers): ?string {
        if (preg_match('/^Nats-Subject:\s*(.+)$/mi', $headers, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Dispatch a single NATS protocol line.
     * Reads additional bytes for MSG/HMSG payload.
     */
    private function dispatch(string $line): void {
        // MSG <subject> <sid> [reply-to] <#bytes>
        if (str_starts_with($line, 'MSG ')) {
            $parts = explode(' ', $line);
            $n = \count($parts);
            $subject = $parts[1];
            $sid = (int) $parts[2];
            $replyTo = $n === 5 ? $parts[3] : null;
            $bytes = (int) $parts[$n - 1];
            $payload = $this->readBytes($bytes);
            $this->readBytes(2); // trailing \r\n

            if (isset($this->handlers[$sid])) {
                ($this->handlers[$sid])($subject, $payload);
            }

            // Auto-ACK for JetStream subscriptions
            if ($replyTo !== null && isset($this->jsSids[$sid])) {
                $this->publish($replyTo, '');
            }

            return;
        }

        // HMSG <subject> <sid> [reply-to] <#hdr-bytes> <#total-bytes>
        // (JetStream push consumers use headers for sequence metadata)
        if (str_starts_with($line, 'HMSG ')) {
            $parts = explode(' ', $line);
            $n = \count($parts);
            $subject = $parts[1];
            $sid = (int) $parts[2];
            $replyTo = $n === 6 ? $parts[3] : null;
            $hdrBytes = (int) $parts[$n - 2];
            $total = (int) $parts[$n - 1];
            $all = $this->readBytes($total);
            $this->readBytes(2); // trailing \r\n
            $headers = substr($all, 0, $hdrBytes);
            $payload = substr($all, $hdrBytes);
            // Recover original NATS subject from JetStream header so handlers
            // can parse the service key from the original subject, not the inbox.
            $natsSubject = $this->extractNatsSubject($headers);

            if (isset($this->handlers[$sid])) {
                ($this->handlers[$sid])($natsSubject ?? $subject, $payload);
            }

            // Auto-ACK for JetStream subscriptions
            if ($replyTo !== null && isset($this->jsSids[$sid])) {
                $this->publish($replyTo, '');
            }

            return;
        }

        if ($line === 'PING') {
            $this->send("PONG\r\n");
        }

        // +OK, -ERR, INFO lines are intentionally ignored
    }
}
