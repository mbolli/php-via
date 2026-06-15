<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\DevBar;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * HTTP surface for the Via Dev Bar, served under `/_via/*`.
 *
 * Routes:
 *   GET  /_via             standalone Dev Console (full-screen panel)
 *   GET  /_via/devbar.css  overlay stylesheet
 *   GET  /_via/devbar.js   overlay web component
 *   GET  /_via/stream      SSE stream of new traces (EventSource)
 *   GET  /_via/scopes      JSON snapshot for the Scopes/Contexts panel
 *   POST /_via/signal      signal write (devMode + writes-enabled only)
 *
 * The pure data methods ({@see buildScopesSnapshot()}, {@see writeSignal()})
 * are split from their HTTP wrappers so they can be unit-tested without
 * OpenSwoole request/response objects.
 */
final class DevBarController {
    private const string ASSET_DIR = __DIR__ . '/../../public';

    public function __construct(private Via $via) {}

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
                $this->serveAsset('devbar.css', 'text/css; charset=utf-8', $response);

                return;

            case '/_via/devbar.js':
                $this->serveAsset('devbar.js', 'application/javascript', $response);

                return;

            case '/_via/stream':
                $this->serveStream($request, $response);

                return;

            case '/_via/scopes':
                $response->header('Content-Type', 'application/json');
                $response->header('Cache-Control', 'no-store');
                $response->end((string) json_encode($this->buildScopesSnapshot()));

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
     * Snapshot of active scopes and their contexts for the Scopes/Contexts panel.
     *
     * @return array{scopes: list<array{scope: string, contextCount: int, contextIds: list<string>}>, totalContexts: int, activeSse: int, clients: int}
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
            'scopes' => $scopes,
            'totalContexts' => \count($this->via->contexts),
            'activeSse' => array_sum($this->via->activeSseCount),
            'clients' => \count($this->via->clients),
        ];
    }

    /**
     * Apply a signal write requested from the Dev Bar.
     *
     * Enforces the hard production guard ({@see Config::isTracingWritesEnabled()}),
     * then sets the value through the framework's normal signal path so scoped
     * broadcasts fire, and syncs the owning context so its browser updates.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function writeSignal(string $contextId, string $signalId, mixed $value): array {
        if (!$this->via->getConfig()->isTracingWritesEnabled()) {
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
     * Clear the server-side trace buffer so the cleared view also survives a
     * reload / a fresh console connection (the front-end clears its own arrays).
     */
    private function handleReset(Request $request, Response $response): void {
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');

        if (!$this->isOriginAllowed($request->header['origin'] ?? null, $request->header['host'] ?? null)) {
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
        if (!$this->isOriginAllowed($request->header['origin'] ?? null, $request->header['host'] ?? null)) {
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

    private function serveAsset(string $file, string $contentType, Response $response): void {
        $path = self::ASSET_DIR . '/' . $file;
        $body = is_file($path) ? file_get_contents($path) : false;

        if ($body === false) {
            $response->status(404);
            $response->end('Not Found');

            return;
        }

        $response->header('Content-Type', $contentType);
        $response->header('Cache-Control', 'no-cache');
        $response->end($body);
    }

    private function serveConsole(Response $response): void {
        $base = $this->via->getConfig()->getBasePath();
        $store = $this->via->getTraceStore();
        $initial = $store !== null ? json_encode($store->recent()) : '[]';
        if ($initial === false) {
            $initial = '[]';
        }
        $writes = $this->via->getConfig()->isTracingWritesEnabled();
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
     * resume each independently via Last-Event-ID. Mirrors the keep-alive/poll
     * pattern of the main SseHandler (usleep is coroutine-safe under SWOOLE_HOOK_ALL).
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
        $pollMs = $this->via->getConfig()->getSsePollIntervalMs();

        while (true) {
            if ($this->via->isShuttingDown() || !$response->isWritable()) {
                break;
            }

            $traces = $traceStore->since($traceCursor);
            $logs = $logBuffer?->since($logCursor) ?? [];

            if ($traces === [] && $logs === []) {
                usleep($pollMs * 1000);

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

    /**
     * Same-origin check for signal writes. Allows absent Origin (curl/dev tools)
     * and matches the Origin host against the Host header when present.
     */
    private function isOriginAllowed(?string $origin, ?string $host): bool {
        $trusted = $this->via->getConfig()->getTrustedOrigins();
        if ($trusted !== null) {
            return $origin === null || \in_array($origin, $trusted, true);
        }

        if ($origin === null || $host === null) {
            return true;
        }

        return preg_replace('#^https?://#', '', $origin) === $host;
    }
}
