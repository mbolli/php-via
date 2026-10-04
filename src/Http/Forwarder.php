<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Process;

/**
 * Passes a request of a tab that reaches the wrong worker to the worker that holds the tab, over OpenSwoole's worker
 * pipe, and writes that worker's answer to the client.
 *
 * With more than one worker a tab's context lives on its home: the worker that holds its SSE stream, or before one
 * connects the worker that rendered or rebuilt it (SharedContextDirectory). An action or a download can reach any
 * worker. One that reaches another worker goes to the home as raw HTTP, and the home runs middleware and handler as
 * for a request of its own, answering through a RelayResponse. Where the tab has no live home, the worker that got
 * the request claims it and runs the request itself, rebuilding the context as a revival does.
 *
 * Every message is a list whose first entry names it:
 * - to the home: req, ack (a chunk is written), gone (the receiver gave up or its client left), requeue (cookies of
 *   a response the receiver gave up on), handover (a stream took the tab elsewhere)
 * - to the receiver: nothome, res (a whole response), head, chunk, end, file (sendfile), close; patches, with TAB
 *   signal values, go to the new home of a tab that was handed over
 *
 * @internal
 */
final class Forwarder {
    public const string MESSAGE_PREFIX = "via.fwd\x00";

    /** Body bytes per chunk: OpenSwoole passes a pipe message over 8 KiB through a temporary file. */
    public const int CHUNK_BYTES = 6144;

    /** Chunks a home sends before it waits for the receiver to have written them. */
    public const int WINDOW = 16;

    /** How often a waiting worker checks that the other one still runs, in seconds. */
    public const float POLL_S = 1.0;

    private const string FORWARDED = 'forwarded';
    private const string NOT_HOME = 'nothome';

    private int $nextId = 0;

    /** @var array<int, Channel> receiver: the messages for each request this worker forwarded, by id */
    private array $waiting = [];

    /** @var array<int, ?string> receiver: the context of each forwarded request, for the cookies of a late answer */
    private array $waitingContexts = [];

    /** @var array<string, RelayResponse> home: the responses of forwarded requests that still run, by sender and id */
    private array $relays = [];

    private int $pid;

    /**
     * @param \Closure(Request, Response): void $serve runs a forwarded request as RequestHandler runs its own
     */
    public function __construct(
        private Via $via,
        private Server $server,
        private int $workerId,
        private int $workerNum,
        private int $timeoutMs,
        private \Closure $serve,
    ) {
        $this->pid = getmypid();
    }

    /**
     * Pass an action to the home of its tab when that is another worker that runs, and answer the client with the
     * home's response.
     *
     * @return bool false when this worker runs it: it is the home, it just claimed the tab, or the tab has no record
     */
    public function forwardAction(Request $request, Response $response): bool {
        $contextId = self::actionContextId($request);
        $app = $this->via->getApp();
        if ($contextId === null || $app->getContextDirectory() === null) {
            return false;
        }

        $refused = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $home = $app->getContextDirectory()->home($contextId);
            if ($home === [$this->workerId, $this->pid]) {
                return false;
            }
            if ($home === null || $home === $refused || !$this->isLive($home)) {
                [$claimed, $found] = $app->claimHome($contextId, false, $home, $this->isLive(...));
                if ($claimed || $found === null) {
                    return false;
                }
                // Another worker took the tab meanwhile.
                $home = $found;
            }

            if ($this->forward($request, $response, $home, 'action', $contextId) === self::FORWARDED) {
                return true;
            }
            $refused = $home;
        }

        return false;
    }

    /**
     * Pass a download to the worker whose id its URL carries, unless that is this one.
     *
     * @return bool false when this worker serves it
     */
    public function forwardDownload(Request $request, Response $response, int $workerId): bool {
        if ($workerId === $this->workerId || $workerId < 0 || $workerId >= $this->workerNum) {
            return false;
        }
        $pid = $this->server->getWorkerPid($workerId);
        if (!\is_int($pid) || $pid <= 0) {
            return false;
        }

        $this->forward($request, $response, [$workerId, $pid], 'download', null);

        return true;
    }

    /**
     * Whether a worker process still runs as the worker it was: a restarted worker has a new process id.
     *
     * @param array{int, int} $home worker id and process id
     */
    public function isLive(array $home): bool {
        [$workerId, $pid] = $home;
        if ($workerId === $this->workerId && $pid === $this->pid) {
            return true;
        }

        return $workerId >= 0 && $workerId < $this->workerNum && $pid > 0
            && $this->server->getWorkerPid($workerId) === $pid && Process::kill($pid, 0);
    }

    /**
     * Ask the worker that held a tab to give it up to this one, which its stream just reached.
     *
     * @param array{int, int} $previous
     */
    public function handOver(string $contextId, array $previous): void {
        if ($previous[0] === $this->workerId || !$this->isLive($previous)) {
            return;
        }
        $this->send($previous[0], ['handover', $contextId]);
    }

    /**
     * Handle a pipe message that starts with MESSAGE_PREFIX.
     *
     * @internal called from the server's pipeMessage event, in a coroutine of its own
     */
    public function receive(int $from, string $data): void {
        $message = unserialize(substr($data, \strlen(self::MESSAGE_PREFIX)), ['allowed_classes' => false]);
        if (!\is_array($message) || !isset($message[0]) || !\is_string($message[0])) {
            return;
        }

        $type = $message[0];
        $id = \is_int($message[1] ?? null) ? $message[1] : 0;

        switch ($type) {
            case 'req':
                $this->serveForwarded($from, $message);

                return;

            case 'ack':
                ($this->relays[$from . ':' . $id] ?? null)?->acked();

                return;

            case 'gone':
                ($this->relays[$from . ':' . $id] ?? null)?->abandon();

                return;

            case 'handover':
                if (\is_string($message[1] ?? null)) {
                    $handed = $this->via->releaseHandedOver($message[1]);
                    $this->sendHandedOver($from, $message[1], $handed['patches'], $handed['signals']);
                }

                return;

            case 'patches':
                if (\is_string($message[1] ?? null) && \is_array($message[2] ?? null)) {
                    $this->via->queueHandedOverPatches($message[1], $message[2], self::decodeSignals($message[3] ?? []));
                }

                return;

            case 'requeue':
                if (\is_string($message[1] ?? null) && \is_array($message[2] ?? null) && \is_array($message[3] ?? null)) {
                    $this->via->requeueResponseCookies($message[1], $message[2], $message[3]);
                }

                return;
        }

        $channel = $this->waiting[$id] ?? null;
        if ($channel !== null) {
            $channel->push($message);

            return;
        }

        // An answer that came after the receiver gave up: its cookies go out with the tab's next response.
        if ($type === 'chunk') {
            $this->send($from, ['gone', $id]);
        } elseif (\in_array($type, ['res', 'head', 'file'], true) && \is_string($message[$type === 'res' ? 6 : 5] ?? null)
            && \is_array($message[3] ?? null) && \is_array($message[4] ?? null)) {
            $this->send($from, ['requeue', $message[$type === 'res' ? 6 : 5], $message[4], $message[3]]);
        }
    }

    /**
     * Send the new home of a tab what its previous home handed over, see Via::releaseHandedOver().
     *
     * @param list<array{type: string, content: string, selector?: string, mode?: string}> $patches
     * @param array<string, mixed>                                                         $signals by signal id
     */
    public function sendHandedOver(int $workerId, string $contextId, array $patches, array $signals): void {
        if ($patches === [] && $signals === []) {
            return;
        }

        // As JSON, the form the browser gets them in: the pipe takes no objects.
        $encoded = [];
        foreach ($signals as $id => $value) {
            $json = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
            if ($json !== false) {
                $encoded[$id] = $json;
            }
        }
        $this->send($workerId, ['patches', $contextId, $patches, $encoded]);
    }

    /**
     * Give the cookies of an action whose receiver gave up to the tab's next response.
     *
     * @param list<array{0: string, 1: list<mixed>}> $cookies cookie calls
     * @param list<array{0: string, 1: string}>      $headers header calls, for a session cookie written as a header
     */
    public function requeueLocally(string $contextId, array $cookies, array $headers): void {
        $this->via->requeueResponseCookies($contextId, $cookies, $headers);
    }

    /**
     * Send a message to a worker.
     *
     * @param list<mixed> $message
     */
    public function send(int $workerId, array $message): bool {
        try {
            return $this->server->sendMessage(self::MESSAGE_PREFIX . serialize($message), $workerId) === true;
        } catch (\Throwable $e) {
            $this->via->log('error', "Sending a message to worker {$workerId} failed: " . Logger::describe($e));

            return false;
        }
    }

    /**
     * The context id an action names: via_ctx in its signals, or in the form fields of a multipart post.
     */
    public static function actionContextId(Request $request): ?string {
        $contextId = SignalParser::read($request)['via_ctx'] ?? $request->post['via_ctx'] ?? null;

        return \is_string($contextId) && $contextId !== '' ? $contextId : null;
    }

    /**
     * The request as HTTP/1.1 bytes, which the home parses as OpenSwoole parses a request of its own, so uploads are
     * its own temporary files there.
     */
    public static function rawRequest(Request $request): string {
        $server = $request->server ?? [];
        $target = (string) ($server['request_uri'] ?? '/');
        $query = (string) ($server['query_string'] ?? '');
        if ($query !== '') {
            $target .= '?' . $query;
        }
        $body = $request->rawContent();
        $body = \is_string($body) ? $body : '';

        $head = strtoupper((string) ($server['request_method'] ?? 'GET')) . ' ' . $target . " HTTP/1.1\r\n";
        $headers = $request->header ?? [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if ($name === '' || $name[0] === ':' || \in_array($name, ['content-length', 'transfer-encoding', 'connection', 'keep-alive', 'upgrade', 'http2-settings', 'te', 'expect'], true)) {
                continue;
            }
            foreach ((array) $value as $line) {
                $head .= $name . ': ' . strtr((string) $line, "\r\n", '  ') . "\r\n";
            }
        }
        if (!isset($headers['host'])) {
            $head .= "host: localhost\r\n";
        }
        // HTTP/2 hands its cookies over parsed only.
        if (!isset($headers['cookie']) && ($request->cookie ?? []) !== []) {
            $pairs = [];
            foreach ($request->cookie as $name => $value) {
                $pairs[] = $name . '=' . rawurlencode((string) $value);
            }
            $head .= 'cookie: ' . implode('; ', $pairs) . "\r\n";
        }

        return $head . 'content-length: ' . \strlen($body) . "\r\n\r\n" . $body;
    }

    /**
     * The signal values of a patches message.
     *
     * @param mixed $encoded the message's entry as unserialized: JSON strings by signal id, or nothing usable
     *
     * @return array<string, mixed> by signal id
     */
    private static function decodeSignals(mixed $encoded): array {
        $signals = [];
        foreach (\is_array($encoded) ? $encoded : [] as $id => $json) {
            if (!\is_string($json)) {
                continue;
            }

            try {
                $signals[(string) $id] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
            }
        }

        return $signals;
    }

    /**
     * Forward a request and relay the answer.
     *
     * @param array{int, int} $target
     */
    private function forward(Request $request, Response $response, array $target, string $kind, ?string $contextId): string {
        $id = ++$this->nextId;
        $channel = new Channel(self::WINDOW + 4);
        $this->waiting[$id] = $channel;
        $this->waitingContexts[$id] = $contextId;

        try {
            $sent = $this->send($target[0], ['req', $id, $kind, $contextId, self::rawRequest($request), $request->server ?? [], $this->pid]);
            if (!$sent) {
                $response->status(503);
                $response->end('Service Unavailable: the worker that holds this tab cannot be reached');

                return self::FORWARDED;
            }

            return $this->relay($channel, $response, $target, $id);
        } finally {
            unset($this->waiting[$id], $this->waitingContexts[$id]);
        }
    }

    /**
     * Write what the home answers. The timeout counts from the request and again from each message, so a long
     * download keeps going while it sends.
     *
     * @param array{int, int} $target
     */
    private function relay(Channel $channel, Response $response, array $target, int $id): string {
        $timeoutNs = $this->timeoutMs * 1_000_000;
        $deadline = hrtime(true) + $timeoutNs;
        $started = false;

        while (true) {
            $message = $channel->pop(max(0.001, min(self::POLL_S, ($deadline - hrtime(true)) / 1e9)));
            if (!\is_array($message)) {
                $stopped = !$this->isLive($target);
                if (!$stopped && hrtime(true) < $deadline) {
                    continue;
                }
                if (!$stopped) {
                    $this->send($target[0], ['gone', $id]);
                }
                $this->via->log('warn', $stopped
                    ? "Worker {$target[0]} stopped before it answered a request it was passed"
                    : "Worker {$target[0]} did not answer a request it was passed within {$this->timeoutMs} ms; see Config::withContextTimeouts(forwardMs:)");
                if ($started) {
                    $response->close();
                } else {
                    $response->status($stopped ? 503 : 504);
                    $response->end($stopped ? 'Service Unavailable: the worker that holds this tab stopped' : 'Gateway Timeout: the worker that holds this tab did not answer in time');
                }

                return self::FORWARDED;
            }

            $deadline = hrtime(true) + $timeoutNs;

            switch ($message[0]) {
                case 'nothome':
                    return $started ? self::FORWARDED : self::NOT_HOME;

                case 'res':
                    self::head($response, $message);
                    $response->end((string) $message[5]);

                    return self::FORWARDED;

                case 'head':
                    self::head($response, $message);
                    $started = true;

                    break;

                case 'chunk':
                    if (!$response->write((string) $message[2])) {
                        $this->send($target[0], ['gone', $id]);

                        return self::FORWARDED;
                    }
                    $this->send($target[0], ['ack', $id]);

                    break;

                case 'end':
                    $response->end();

                    return self::FORWARDED;

                case 'file':
                    self::head($response, $message);
                    $response->sendfile((string) $message[6], (int) $message[7], (int) $message[8]);

                    return self::FORWARDED;

                case 'close':
                    $response->close();

                    return self::FORWARDED;
            }
        }
    }

    /**
     * Apply the status, headers and cookies of a res, head or file message.
     *
     * @param array<int, mixed> $message
     */
    private static function head(Response $response, array $message): void {
        $response->status((int) $message[2]);
        foreach ((array) $message[3] as [$name, $value]) {
            $response->header((string) $name, (string) $value);
        }
        foreach ((array) $message[4] as [$method, $args]) {
            if ($method === 'rawcookie') {
                $response->rawcookie(...$args);
            } else {
                $response->cookie(...$args);
            }
        }
    }

    /**
     * Run a request another worker passed here.
     *
     * @param array<int, mixed> $message
     */
    private function serveForwarded(int $from, array $message): void {
        [, $id, $kind, $contextId, $raw, $server, $pid] = $message + [null, 0, '', null, '', [], 0];
        if (!\is_int($id) || !\is_string($raw) || !\is_array($server) || !\is_int($pid)) {
            return;
        }
        $contextId = \is_string($contextId) ? $contextId : null;

        // A home never passes a request on: the receiver decides again when this one does not hold the tab.
        if ($kind === 'action' && ($contextId === null || !$this->holdsAsHome($contextId))) {
            $this->send($from, ['nothome', $id]);

            return;
        }

        $response = new RelayResponse($this, [$from, $pid], $id, $this->timeoutMs, $kind === 'action' ? $contextId : null);
        $request = $this->parse($raw, $server);
        if ($request === null) {
            $response->status(400);
            $response->end('Bad Request');

            return;
        }

        $key = $from . ':' . $id;
        $this->relays[$key] = $response;

        try {
            ($this->serve)($request, $response);
        } catch (\Throwable $e) {
            $this->via->log('error', 'A request passed by worker ' . $from . ' failed: ' . Logger::describe($e));
        } finally {
            unset($this->relays[$key]);
            if (!$response->isEnded()) {
                $response->status(500);
                $response->end('Internal Server Error');
            }
        }
    }

    /**
     * Whether this worker holds a tab as its home.
     */
    private function holdsAsHome(string $contextId): bool {
        $context = $this->via->contexts[$contextId] ?? null;
        if ($this->via->isShuttingDown() || $context === null || $context->isDestroyed()) {
            return false;
        }

        $home = $this->via->getApp()->getContextDirectory()?->home($contextId);

        return $home === null || $home === [$this->workerId, $this->pid];
    }

    /**
     * Parse forwarded HTTP bytes into a request, with the connection's server parameters of the worker that got it.
     *
     * @param array<string, mixed> $server
     */
    private function parse(string $raw, array $server): ?Request {
        $uploadDir = $this->server->setting['upload_tmp_dir'] ?? null;
        $request = Request::create(['parse_cookie' => true, 'parse_body' => true, 'parse_files' => true, 'upload_tmp_dir' => \is_string($uploadDir) ? $uploadDir : '/tmp']);
        if (!$request instanceof Request || $request->parse($raw) !== \strlen($raw) || !$request->isCompleted()) {
            return null;
        }
        $request->server = array_merge($request->server ?? [], $server);

        return $request;
    }
}
