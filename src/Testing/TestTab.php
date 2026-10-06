<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use Psr\Http\Message\ResponseInterface;

/**
 * A browser tab on a TestApp: one page load, its SSE stream and its actions.
 *
 * The tab holds the signal values the browser would: the page seeds them, each signal patch the
 * stream sends updates them, and every action and connect sends them back, as Datastar does. Name a
 * signal or an action as the page handler names it, and a component's as 'namespace.name'.
 *
 * @phpstan-type Patch array{type: 'elements', html: string, selector: null|string, mode: PatchMode, viewTransition?: string|true}|array{type: 'signals', signals: array<string, mixed>}
 */
final class TestTab {
    private string $contextId;

    /** @var array<string, mixed> signal id => value, as the browser holds them */
    private array $signals;

    /** @var array<string, string> name => signal id, kept for a revival, which keeps the ids */
    private array $signalIds = [];

    /** @var array<string, string> name => action id */
    private array $actionIds = [];

    /** @var null|\Fiber<mixed, mixed, mixed, mixed> */
    private ?\Fiber $stream = null;
    private ?TestResponse $streamResponse = null;
    private ?Context $streamContext = null;
    private SseReader $reader;

    /** @var list<array{type: 'elements', html: string, selector: null|string, mode: PatchMode, viewTransition?: string|true}|array{type: 'signals', signals: array<array-key, mixed>, onlyIfMissing: bool}> */
    private array $received = [];

    /**
     * @internal TestApp::open() and open() load tabs
     *
     * @param array<string, mixed> $query
     */
    public function __construct(private TestApp $app, private CookieJar $cookies, string $path, array $query, bool $connect) {
        $this->reader = new SseReader();
        $via = $app->via();
        $before = $via->contexts;
        $response = new TestResponse();
        $app->send(new TestRequest('GET', $path, self::query($query), $cookies->all()), $response);
        $cookies->take($response);

        if ($response->statusCode !== 200) {
            throw new \RuntimeException(\sprintf('GET %s answered %d: %s', $path, $response->statusCode, mb_substr(trim($response->body), 0, 500)));
        }
        $created = array_keys(array_diff_key($via->contexts, $before));
        if (\count($created) !== 1) {
            throw new \RuntimeException("GET {$path} answered 200 without a page: a middleware or the notFound() handler answered it.");
        }

        $this->contextId = (string) $created[0];
        // The values the page seeds, as via_head and the seed meta carry them.
        $this->signals = self::merge(
            ['via_ctx' => $this->contextId, '_disconnected' => false],
            $via->contexts[$this->contextId]->getPatchManager()->initialSignalValues(),
        );
        $this->learnNames();

        if ($connect) {
            $this->connect();
        }
    }

    /**
     * Open $path in another tab of this tab's browser: same cookies, same session.
     *
     * @param array<string, mixed> $query the query string, which reaches the app as strings
     *
     * @throws \RuntimeException when the page answers with a status other than 200, or with no page
     */
    public function open(string $path, array $query = [], bool $connect = true): self {
        return new self($this->app, $this->cookies, $path, $query, $connect);
    }

    /**
     * Post action $name, as a click on its URL does: with $input as the query string and every signal the
     * tab holds, after $signals, by name, changed them as a bound input would.
     *
     * A tab whose context was destroyed is revived by it, as on a server. The patches the action queues
     * reach patches() while the tab is connected, and wait for connect() while it is not.
     *
     * @param string               $name    the action's name, 'namespace.name' for a component's; any other
     *                                      value is posted as the action id
     * @param array<string, mixed> $input   read with $c->input(), as strings
     * @param array<string, mixed> $signals signal name => the value the browser now holds
     *
     * @throws \InvalidArgumentException for a signal name the page does not have
     * @throws \RuntimeException         when the action answers with a status other than 200, as one that
     *                                   throws answers 500; the message has the error php-via logged
     */
    public function action(string $name, array $input = [], array $signals = []): self {
        $this->read();
        $this->learnNames();
        foreach ($signals as $signal => $value) {
            $this->signals[$this->signalId((string) $signal)] = $value;
        }

        $response = new TestResponse();
        $request = new TestRequest(
            'POST',
            '/_action/' . ($this->actionIds[$name] ?? $name),
            self::query($input),
            $this->cookies->all(),
            ['origin' => $this->app->origin(), 'content-type' => 'application/json'],
            json_encode(self::sendable($this->signals), JSON_THROW_ON_ERROR),
        );
        $log = $this->app->send($request, $response);
        $this->cookies->take($response);
        $this->read();
        $this->learnNames();

        if ($response->statusCode !== 200) {
            $errors = preg_match_all('/^\[ERROR\] .*$/m', $log, $m) > 0 ? "\n" . implode("\n", $m[0]) : '';

            throw new \RuntimeException(\sprintf("Action '%s' answered %d %s%s", $name, $response->statusCode, $response->body, $errors));
        }

        return $this;
    }

    /**
     * Send a plain HTTP request from this tab's browser, with its cookies, such as a fetch of a Context::download()
     * URL or of a Via::route() behind session middleware.
     *
     * @param string                $path    the path, with its query string
     * @param array<string, string> $headers by name
     *
     * @throws \RuntimeException when the response breaks off, as a download whose source throws midway does
     */
    public function request(string $method, string $path, string $body = '', array $headers = []): ResponseInterface {
        return $this->app->fetch($this->cookies, $method, $path, $body, $headers);
    }

    /**
     * The patches the stream sent since the last call, beginning with those of the connect.
     *
     * An element patch has the HTML, the selector (null when Datastar matches by the element's id) and the
     * mode; a script from execScript() is one too, a <script> appended to body. A signal patch has the
     * values by signal name, '_disconnected' among them on a connect.
     *
     * @return list<Patch>
     */
    public function patches(): array {
        $this->read();
        $this->learnNames();
        $names = array_flip($this->signalIds);
        $patches = [];
        foreach ($this->received as $patch) {
            if ($patch['type'] === 'elements') {
                $patches[] = $patch;

                continue;
            }

            $signals = [];
            foreach ($patch['signals'] as $id => $value) {
                $signals[$names[(string) $id] ?? (string) $id] = $value;
            }
            $patches[] = ['type' => 'signals', 'signals' => $signals];
        }
        $this->received = [];

        return $patches;
    }

    /**
     * This tab's page as a page load renders it now, from the state on the server.
     *
     * What reached the browser is in patches(): a change the app sends no patch for shows here only.
     *
     * @throws \LogicException while the tab has no context (see context()), and after TestApp::shutdown()
     */
    public function html(): string {
        $via = $this->app->via();
        $context = $this->context();
        $html = '';
        $this->app->run(static function () use ($via, $context, &$html): void {
            $html = $via->buildHtmlDocument($context);
        });

        return $html;
    }

    /**
     * The value the browser holds for signal $name, null when it holds none.
     *
     * @param string $name the signal's name, 'namespace.name' for a component's
     *
     * @throws \InvalidArgumentException for a name the page does not have
     */
    public function signal(string $name): mixed {
        $this->read();

        return $this->signals[$this->signalId($name)] ?? null;
    }

    /**
     * Open the SSE stream, as the page's bootstrap does, with the signals the tab holds.
     *
     * It runs the connect through php-via's SSE handler: a destroyed context is revived from those signals,
     * and the initial sync and the patches queued meanwhile reach patches(). When the context cannot be
     * revived, the stream sends a reload script and ends, and the tab stays disconnected.
     *
     * @throws \LogicException   while the tab is connected
     * @throws \RuntimeException when the connect answers with a status other than 200
     */
    public function connect(): self {
        $this->read();
        if ($this->isStreaming()) {
            throw new \LogicException('This tab is connected already: disconnect() first.');
        }

        $request = new TestRequest(
            'GET',
            '/_sse',
            ['datastar' => json_encode(self::sendable($this->signals), JSON_THROW_ON_ERROR)],
            $this->cookies->all(),
            ['accept' => 'text/event-stream'],
        );
        $response = new TestResponse($this->app->nextFd());
        $this->streamResponse = $response;
        $this->stream = $this->app->stream($request, $response);
        $this->streamContext = $this->app->via()->contexts[$this->contextId] ?? null;
        $this->read();

        if (!$this->isStreaming() && $response->statusCode !== 200) {
            throw new \RuntimeException(\sprintf('The SSE connect answered %d: %s', $response->statusCode, $response->body));
        }

        return $this;
    }

    /**
     * End the SSE stream, as a closed tab or a lost connection does: onClientDisconnect runs and the
     * patches queued afterwards wait for the next connect(). Nothing happens while it is not connected.
     *
     * @param bool $expire then let the cleanup delay pass: the context is destroyed, and the next
     *                     connect() or action() revives it from the signals the tab holds
     */
    public function disconnect(bool $expire = false): self {
        if ($this->isStreaming()) {
            $this->streamResponse?->hangUp();
            $context = $this->streamContext;
            $this->app->run(static fn () => $context?->getPatchManager()->wakeConsumers());
            if ($this->stream?->isTerminated() === false) {
                throw new \LogicException('The SSE loop of this tab did not end on a hang-up.');
            }
            $this->endStream();
        }
        $this->read();

        $via = $this->app->via();
        if ($expire && isset($via->contexts[$this->contextId])) {
            $contextId = $this->contextId;
            // What the cleanup timer does when it fires: destroy the context and keep its revival record.
            $this->app->run(static function () use ($via, $contextId): void {
                $via->scheduleContextCleanup($contextId);
                $via->getApp()->cancelContextCleanup($contextId);
                $via->getApp()->destroyContext($contextId);
            });
        }

        return $this;
    }

    /**
     * The tab's context on the server, the page's and not a component's.
     *
     * @throws \LogicException while it is destroyed: connect() or action() revives it
     */
    public function context(): Context {
        return $this->app->via()->contexts[$this->contextId]
            ?? throw new \LogicException("The context {$this->contextId} of this tab is destroyed: connect() or action() revives it.");
    }

    /**
     * Whether the stream runs, and the bookkeeping of one that ended on its own: its context was
     * destroyed, the app stopped, or the connect ended it at once.
     */
    private function isStreaming(): bool {
        if ($this->stream?->isTerminated() === true) {
            $this->endStream();
        }

        return $this->stream !== null;
    }

    private function endStream(): void {
        $this->read();
        $this->stream = $this->streamContext = null;
        // The cleanup the stream's end armed: TestApp has no clock, and disconnect(expire: true) stands for it.
        $this->app->via()->getApp()->cancelContextCleanup($this->contextId);
    }

    /**
     * Take what the stream wrote, and apply its signal patches to the signals the tab holds.
     */
    private function read(): void {
        $text = $this->streamResponse?->takeBody() ?? '';
        if ($text === '') {
            return;
        }

        foreach ($this->reader->read($text) as $patch) {
            if ($patch['type'] === 'signals') {
                $this->signals = self::merge($this->signals, $patch['signals'], $patch['onlyIfMissing']);
            }
            $this->received[] = $patch;
        }
    }

    /**
     * Note the ids of the page's signals and actions, and of its components', while the context lives.
     */
    private function learnNames(?Context $context = null, string $prefix = ''): void {
        $context ??= $this->app->via()->contexts[$this->contextId] ?? null;
        if ($context === null) {
            return;
        }

        foreach ($context->getNamedSignals() as $name => $signal) {
            $this->signalIds[$prefix . $name] = $signal->id();
        }
        foreach ($context->getNamedActions() as $name => $action) {
            $this->actionIds[$prefix . $name] = $action->id();
        }
        foreach ($context->getComponentRegistry() as $component) {
            $this->learnNames($component, $component->getNamespace() . '.');
        }
    }

    private function signalId(string $name): string {
        $this->learnNames();

        return $this->signalIds[$name]
            ?? throw new \InvalidArgumentException("The page of this tab has no signal '{$name}'. It has: " . implode(', ', array_keys($this->signalIds)));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<array-key, mixed> $input as a query string carries it
     */
    private static function query(array $input): array {
        parse_str(http_build_query($input), $query);

        return $query;
    }

    /**
     * Apply a signal patch as Datastar does, a JSON merge patch: null deletes, an object merges into an object.
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $patch
     *
     * @return array<string, mixed>
     */
    private static function merge(array $target, array $patch, bool $onlyIfMissing = false): array {
        $merged = [];
        foreach ($target as $key => $value) {
            $merged[(string) $key] = $value;
        }

        foreach ($patch as $key => $value) {
            $key = (string) $key;
            if ($onlyIfMissing && \array_key_exists($key, $merged)) {
                continue;
            }
            if ($value === null) {
                unset($merged[$key]);
            } elseif (self::isObject($value)) {
                $base = $merged[$key] ?? null;
                $merged[$key] = self::merge(self::isObject($base) ? $base : [], $value, $onlyIfMissing);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * The signals Datastar sends with a request: all but those whose name starts with an underscore.
     *
     * @param array<array-key, mixed> $signals
     *
     * @return array<string, mixed>
     */
    private static function sendable(array $signals): array {
        $sent = [];
        foreach ($signals as $key => $value) {
            $key = (string) $key;
            if (!str_starts_with($key, '_')) {
                $sent[$key] = self::isObject($value) ? self::sendable($value) : $value;
            }
        }

        return $sent;
    }

    /**
     * Whether a signal value is a JSON object, which Datastar keeps as nested signals.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isObject(mixed $value): bool {
        return \is_array($value) && $value !== [] && !array_is_list($value);
    }
}
