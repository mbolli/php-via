<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\DevBar;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Settings;
use Mbolli\PhpVia\Http\OriginPolicy;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\StaticBrotli;
use Mbolli\PhpVia\Support\ConditionalGet;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * HTTP surface for the Via Dev Bar, served under `/_via/*`.
 *
 * Routes:
 *   GET  /_via             standalone Dev Console (full-screen panel)
 *   GET  /_via/devbar.css  overlay stylesheet (HEAD too)
 *   GET  /_via/devbar.js   overlay web component (HEAD too)
 *   GET  /_via/stream      SSE stream of new traces (EventSource)
 *   GET  /_via/scopes      JSON snapshot for the Scopes/Contexts panel
 *   GET  /_via/stats       JSON snapshot for the Stats panel
 *   POST /_via/signal      signal write (devMode + writes-enabled only)
 *
 * The pure data methods ({@see buildScopesSnapshot()}, {@see buildStatsSnapshot()}, {@see writeSignal()})
 * are split from their HTTP wrappers so they can be unit-tested without
 * OpenSwoole request/response objects.
 */
final class DevBarController {
    private const string ASSET_DIR = __DIR__ . '/../../public';

    /** The hook flags the Stats panel names. */
    private const array HOOK_FLAGS = [
        'TCP' => SWOOLE_HOOK_TCP,
        'UDP' => SWOOLE_HOOK_UDP,
        'UNIX' => SWOOLE_HOOK_UNIX,
        'UDG' => SWOOLE_HOOK_UDG,
        'SSL' => SWOOLE_HOOK_SSL,
        'TLS' => SWOOLE_HOOK_TLS,
        'STREAM_FUNCTION' => SWOOLE_HOOK_STREAM_FUNCTION,
        'FILE' => SWOOLE_HOOK_FILE,
        'STDIO' => SWOOLE_HOOK_STDIO,
        'SLEEP' => SWOOLE_HOOK_SLEEP,
        'PROC' => SWOOLE_HOOK_PROC,
        'CURL' => SWOOLE_HOOK_CURL,
        'NATIVE_CURL' => SWOOLE_HOOK_NATIVE_CURL,
        'BLOCKING_FUNCTION' => SWOOLE_HOOK_BLOCKING_FUNCTION,
        'SOCKETS' => SWOOLE_HOOK_SOCKETS,
    ];

    /** @var array<string, array{mtime: int, body: string, etag: string}> by file name */
    private array $assets = [];

    private StaticBrotli $staticBrotli;

    public function __construct(private Via $via, private string $assetDir = self::ASSET_DIR, ?StaticBrotli $staticBrotli = null) {
        $this->staticBrotli = $staticBrotli ?? new StaticBrotli($via->getSettings(), $via->log(...));
    }

    /**
     * Where a shipped Dev Bar asset lives.
     *
     * @internal used to compress the assets at start
     */
    public static function defaultAssetPath(string $file): string {
        return self::ASSET_DIR . '/' . $file;
    }

    /**
     * Dispatch a `/_via/*` request. Caller has already verified tracing is on
     * and that the HTTP method matches.
     */
    public function handle(string $path, Request $request, Response $response): void {
        switch ($path) {
            case '/_via':
            case '/_traces':
                $this->serveConsole($response);

                return;

            case '/_via/devbar.css':
                $this->serveAsset('devbar.css', 'text/css; charset=utf-8', $request, $response);

                return;

            case '/_via/devbar.js':
                $this->serveAsset('devbar.js', 'application/javascript', $request, $response);

                return;

            case '/_via/stream':
                $this->serveStream($request, $response);

                return;

            case '/_via/scopes':
                $response->header('Content-Type', 'application/json');
                $response->header('Cache-Control', 'no-store');
                $response->end((string) json_encode($this->buildScopesSnapshot()));

                return;

            case '/_via/stats':
                $response->header('Content-Type', 'application/json');
                $response->header('Cache-Control', 'no-store');
                $response->end((string) json_encode($this->buildStatsSnapshot()));

                return;

            case '/_via/signal':
                $this->handleSignalWrite($request, $response);

                return;

            case '/_via/reset':
                $this->handleReset($request, $response);

                return;

            default:
                $response->status(404);
                $response->end('Not Found');
        }
    }

    /**
     * Snapshot of the active scopes and their contexts for the Scopes panel, from the worker that answers.
     *
     * @return array{worker: int, scopes: list<array{scope: string, contextCount: int, contextIds: list<string>}>, totalContexts: int, activeSse: int, clients: int}
     */
    public function buildScopesSnapshot(): array {
        $registry = $this->via->getScopeRegistry();

        $scopes = [];
        foreach ($registry->getAllScopes() as $scope) {
            $contexts = $registry->getContextsByScope($scope);
            $scopes[] = [
                'scope' => $scope,
                'contextCount' => \count($contexts),
                'contextIds' => array_map(static fn (Context $c) => $c->getId(), $contexts),
            ];
        }

        return [
            'worker' => $this->via->getApp()->workerIdentity()[0],
            'scopes' => $scopes,
            'totalContexts' => \count($this->via->contexts),
            'activeSse' => array_sum($this->via->activeSseCount),
            'clients' => \count($this->via->clients),
        ];
    }

    /**
     * Snapshot for the Stats panel, from the worker that answers: every Via::getStats()->getAll() figure, the broadcast
     * tick, and the runtime figures of the dev-mode /_stats with the hook flags named.
     *
     * @return array{worker: int, stats: array<string, float|int>, broadcast_tick_ms: int, runtime: array<string, float|int>, hook_flag_names: list<string>}
     */
    public function buildStatsSnapshot(): array {
        $runtime = RequestHandler::runtimeStats($this->via->getServer());
        $flags = (int) $runtime['hook_flags'];

        return [
            'worker' => $this->via->getApp()->workerIdentity()[0],
            'stats' => $this->via->getStats()->getAll(),
            'broadcast_tick_ms' => $this->via->getSettings()->broadcastTickMs,
            'runtime' => $runtime,
            'hook_flag_names' => array_keys(array_filter(self::HOOK_FLAGS, static fn (int $flag): bool => ($flags & $flag) === $flag)),
        ];
    }

    /**
     * Apply a signal write requested from the Dev Bar.
     *
     * Enforces the hard production guard ({@see Settings::tracingWritesEnabled()}),
     * then sets the value through the framework's normal signal path so scoped
     * broadcasts fire, and syncs the owning context so its browser updates.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function writeSignal(string $contextId, string $signalId, mixed $value): array {
        if (!$this->via->getSettings()->tracingWritesEnabled()) {
            return ['status' => 403, 'body' => ['error' => 'Signal writes are disabled']];
        }

        $context = $this->via->contexts[$contextId] ?? null;
        if ($context === null) {
            return ['status' => 404, 'body' => ['error' => 'Unknown context']];
        }

        $target = null;
        foreach ($context->getSignals() as $signal) {
            if ($signal->id() === $signalId) {
                $target = $signal;

                break;
            }
        }

        if ($target === null) {
            return ['status' => 404, 'body' => ['error' => 'Unknown signal']];
        }

        // Goes through Signal::setValue → scoped signals broadcast to peers.
        $target->setValue($value);
        // Push to this context's own browser (covers TAB-scoped signals).
        $context->sync();

        return ['status' => 200, 'body' => ['id' => $signalId, 'value' => $value]];
    }

    /**
     * A Dev Bar asset, read once and served from memory with an ETag, and with Brotli at the static
     * level when the client accepts it. Dev mode re-reads a file after an edit.
     *
     * @param array<string, string> $requestHeaders lower-cased names, as OpenSwoole passes them
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function assetResponse(string $file, string $contentType, array $requestHeaders): array {
        $asset = $this->loadAsset($file);
        if ($asset === null) {
            return ['status' => 404, 'headers' => [], 'body' => 'Not Found'];
        }

        $brotli = $this->staticBrotli;
        $headers = [
            'Cache-Control' => 'no-cache',
            'ETag' => $asset['etag'],
            'Last-Modified' => ConditionalGet::lastModified($asset['mtime']),
        ];
        if ($brotli->enabled()) {
            $headers['Vary'] = 'Accept-Encoding';
        }

        if (ConditionalGet::isNotModified($requestHeaders['if-none-match'] ?? null, $requestHeaders['if-modified-since'] ?? null, $asset['etag'], $asset['mtime'])) {
            return ['status' => 304, 'headers' => $headers, 'body' => ''];
        }

        $headers['Content-Type'] = $contentType;
        if ($brotli->enabled() && str_contains($requestHeaders['accept-encoding'] ?? '', 'br')) {
            $path = $this->assetDir . '/' . $file;
            $compressed = $brotli->lookup($path, $asset['mtime'], \strlen($asset['body']), $asset['body']);
            if ($brotli->pending($path, $asset['mtime'], \strlen($asset['body']))) {
                $headers['Cache-Control'] = 'no-store';
            }
            if (isset($compressed['body'])) {
                $headers['Content-Encoding'] = 'br';

                return ['status' => 200, 'headers' => $headers, 'body' => $compressed['body']];
            }
        }

        return ['status' => 200, 'headers' => $headers, 'body' => $asset['body']];
    }

    /**
     * Clear the server-side trace buffer so the cleared view also survives a
     * reload / a fresh console connection (the front-end clears its own arrays).
     */
    private function handleReset(Request $request, Response $response): void {
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');

        if (!OriginPolicy::allows($this->via->getSettings(), $request->header['origin'] ?? null, $request->header['host'] ?? null)) {
            $response->status(403);
            $response->end((string) json_encode(['error' => 'Untrusted origin']));

            return;
        }

        $this->via->getTraceStore()?->clear();

        $response->status(200);
        $response->end((string) json_encode(['ok' => true]));
    }

    private function handleSignalWrite(Request $request, Response $response): void {
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');

        // Defence in depth: same-origin check (writes are devMode-only already).
        if (!OriginPolicy::allows($this->via->getSettings(), $request->header['origin'] ?? null, $request->header['host'] ?? null)) {
            $response->status(403);
            $response->end((string) json_encode(['error' => 'Untrusted origin']));

            return;
        }

        $payload = json_decode($request->rawContent() ?: '', true);
        if (!\is_array($payload) || !isset($payload['contextId'], $payload['signalId']) || !\array_key_exists('value', $payload)) {
            $response->status(400);
            $response->end((string) json_encode(['error' => 'Expected {contextId, signalId, value}']));

            return;
        }

        $result = $this->writeSignal((string) $payload['contextId'], (string) $payload['signalId'], $payload['value']);
        $response->status($result['status']);
        $response->end((string) json_encode($result['body']));
    }

    private function serveAsset(string $file, string $contentType, Request $request, Response $response): void {
        $result = $this->assetResponse($file, $contentType, $request->header ?? []);

        $response->status($result['status']);
        foreach ($result['headers'] as $name => $value) {
            $response->header($name, $value);
        }
        RequestHandler::endWithBody($request, $response, $result['body']);
    }

    /**
     * @return null|array{mtime: int, body: string, etag: string}
     */
    private function loadAsset(string $file): ?array {
        $cached = $this->assets[$file] ?? null;
        if ($cached !== null && !$this->via->getSettings()->devMode) {
            return $cached;
        }

        $path = $this->assetDir . '/' . $file;
        // PHP's stat cache would hide an edit made while the server runs.
        clearstatcache(true, $path);
        $mtime = is_file($path) ? filemtime($path) : false;
        if ($mtime === false) {
            return null;
        }
        if ($cached !== null && $cached['mtime'] === $mtime) {
            return $cached;
        }

        $body = file_get_contents($path);
        if ($body === false) {
            return null;
        }

        return $this->assets[$file] = ['mtime' => $mtime, 'body' => $body, 'etag' => 'W/"' . hash('xxh3', $body) . '"'];
    }

    private function serveConsole(Response $response): void {
        $base = $this->via->getSettings()->basePath;
        $store = $this->via->getTraceStore();
        $initial = $store !== null ? json_encode($store->recent()) : '[]';
        if ($initial === false) {
            $initial = '[]';
        }
        $writes = $this->via->getSettings()->tracingWritesEnabled();
        $config = htmlspecialchars(
            (string) json_encode(['mode' => 'page', 'base' => $base, 'writes' => $writes]),
            ENT_QUOTES,
            'UTF-8',
        );

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Via Dev Console</title>
                <link rel="icon" href="data:,">
                <link rel="stylesheet" href="{$base}_via/devbar.css">
                <script>window.__VIA_TRACES__ = {$initial};</script>
            </head>
            <body style="margin:0">
                <via-dev-bar via-config='{$config}'></via-dev-bar>
                <script type="module" src="{$base}_via/devbar.js"></script>
            </body>
            </html>
            HTML;

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->header('Cache-Control', 'no-store');
        $response->end($html);
    }

    /**
     * Long-lived SSE stream multiplexing two record types as named events:
     * `trace` (from the trace store) and `log` (from the log buffer).
     *
     * The front-end consumes this with EventSource + addEventListener. The SSE
     * id carries both cursors as "{traceCursor}.{logCursor}" so a reconnect can
     * resume each independently via Last-Event-ID. Polls the buffers every
     * withDevBarOptions(pollMs:).
     */
    private function serveStream(Request $request, Response $response): void {
        $traceStore = $this->via->getTraceStore();
        if ($traceStore === null) {
            $response->status(404);
            $response->end('Not Found');

            return;
        }
        $logBuffer = $this->via->getLogBuffer();

        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        [$traceCursor, $logCursor] = self::parseCursor($request->header['last-event-id'] ?? '');
        $pollMs = $this->via->getSettings()->ssePollIntervalMs;

        while (true) {
            if ($this->via->isShuttingDown() || !$response->isWritable()) {
                break;
            }

            $traces = $traceStore->since($traceCursor);
            $logs = $logBuffer?->since($logCursor) ?? [];

            if ($traces === [] && $logs === []) {
                Coroutine::usleep($pollMs * 1000);

                continue;
            }

            foreach ($traces as $trace) {
                $traceCursor = max($traceCursor, (int) ($trace['seq'] ?? $traceCursor));
                if (!$this->writeFrame($response, 'trace', $trace, $traceCursor, $logCursor)) {
                    return;
                }
            }

            foreach ($logs as $log) {
                $logCursor = max($logCursor, $log['seq']);
                if (!$this->writeFrame($response, 'log', $log, $traceCursor, $logCursor)) {
                    return;
                }
            }
        }
    }

    /**
     * @return array{0: int, 1: int} [traceCursor, logCursor]
     */
    private static function parseCursor(string $lastEventId): array {
        if (str_contains($lastEventId, '.')) {
            [$t, $l] = explode('.', $lastEventId, 2);

            return [(int) $t, (int) $l];
        }

        // Legacy single-cursor id (trace only).
        return [(int) $lastEventId, 0];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeFrame(Response $response, string $event, array $payload, int $traceCursor, int $logCursor): bool {
        $json = json_encode($payload);
        if ($json === false) {
            return true;
        }

        $frame = "event: {$event}\nid: {$traceCursor}.{$logCursor}\ndata: {$json}\n\n";

        try {
            return $response->write($frame) !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
