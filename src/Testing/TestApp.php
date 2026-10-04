<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;

/**
 * An app under test: php-via serving its pages in the test's process, with no server and no port.
 *
 * ```php
 * $app = new TestApp($config, fn (Via $via) => CounterPage::register($via));
 * $tab = $app->open('/counter');
 * $tab->patches();
 * $tab->action('increment');
 * expect($tab->signal('count'))->toBe(1);
 * $app->shutdown();
 * ```
 *
 * Page loads, actions and SSE streams go through php-via's own request, action and SSE handlers, so
 * middleware, session and origin checks, revival and the post-action signal send run as on a server.
 * An open stream runs its real SSE loop in a Fiber, parked until a patch arrives, as a coroutine parks.
 * What php-via logs meanwhile is kept for logs() instead of printed.
 *
 * It does not simulate:
 * - more than one worker: everything runs in one process as worker 0, without the tables that share
 *   state between workers, and no broker is connected, so leave withBroker() out of the Config
 * - real timing: a broadcast from a page handler or an action renders at once where a server renders it
 *   on the next broadcast tick, and no Config::withBroadcastThrottle() holds it back, so two broadcasts in
 *   one action send two frames where a server may send one; disconnect(expire: true) stands for the
 *   cleanup delay passing
 * - an event loop, except in runTasks(): a coroutine the app starts, such as a Context::spawn() task,
 *   runs only up to its first wait, and timers fire only in runTasks(), which waits for spawn() tasks and
 *   not for coroutines started with Coroutine::create(). Via::setInterval() timers are armed as worker 0
 *   arms them, all of them, and fire there like Context::setInterval() ones
 * - the network: no slow clients or dropped frames, and no HTTP/2 stream resets
 * - the browser past its signals: html() renders the page from server state, no DOM applies patches,
 *   and actions post JSON, never a form or a file upload
 *
 * It needs no VIA_TEST_MODE. Call shutdown() when done, or let the TestApp go out of scope; it waits for
 * running tasks as a stopping worker does. Once a coroutine has run in a process, as a task does,
 * OpenSwoole disables pcntl_fork() there for good.
 */
final class TestApp {
    private RecordingVia $via;
    private RequestHandler $handler;
    private bool $stopped = false;
    private int $lastFd = 0;

    /** @var list<string> */
    private array $logged = [];

    /**
     * new Via($config) freezes $config, as always.
     *
     * @param callable(Via): void $routes registers the pages, as the app's bootstrap does; onWorkerStart
     *                                    callbacks it registers run after it, as worker 0, and then its
     *                                    Via::setInterval() timers are armed
     */
    public function __construct(Config $config, callable $routes) {
        $this->capture(function () use ($config, $routes): void {
            $this->via = new RecordingVia($config);
            // Weak, so the Via holds no TestApp and a dropped TestApp still reaches its destructor.
            $app = \WeakReference::create($this);
            $this->via->logCoroutinesTo(static fn (string $output) => $app->get()?->keep($output));
            $routes($this->via);
            $this->handler = $this->via->serveInProcess();
        });
    }

    public function __destruct() {
        $this->shutdown();
    }

    /**
     * Load $path in a new browser, with a session of its own. TestTab::open() opens another tab of that browser.
     *
     * @param array<string, mixed> $query   the query string, which reaches the app as strings
     * @param bool                 $connect open the SSE stream after the page loads, as the page's bootstrap does
     *
     * @throws \RuntimeException when the page answers with a status other than 200, or with no page
     */
    public function open(string $path, array $query = [], bool $connect = true): TestTab {
        return new TestTab($this, new CookieJar(), $path, $query, $connect);
    }

    /**
     * Send a plain HTTP request from a client without cookies, such as an API client calling a Via::route().
     * TestTab::request() sends one with its browser's cookies, as a download link needs. An
     * application/x-www-form-urlencoded body reaches PSR-7's getParsedBody() as on a server; a multipart one does not.
     *
     * @param string                $path    the path, with its query string
     * @param array<string, string> $headers by name
     *
     * @throws \RuntimeException when the response breaks off, as a download whose source throws midway does
     */
    public function request(string $method, string $path, string $body = '', array $headers = []): ResponseInterface {
        return $this->fetch(new CookieJar(), $method, $path, $body, $headers);
    }

    /**
     * Run the event loop until the Context::spawn() tasks have ended and the broadcasts they made have rendered.
     *
     * A task runs inside the action that starts it up to its first wait (a sleep, a Channel, socket I/O); this
     * runs the rest, and the patches its sync() calls queue reach the tabs. Meanwhile time passes as on a
     * server: timers fire, Context::setInterval() and Via::setInterval() callbacks included, and a task's
     * broadcasts wait for the broadcast tick and any Config::withBroadcastThrottle(). With no task and no
     * broadcast pending it returns at once, and no timer fires.
     *
     * It waits for spawn() tasks and broadcasts only. A coroutine started with Coroutine::create(), such as an
     * app-wide import or daemon that belongs to no tab, runs only while something else keeps the loop busy: a test
     * calls such work directly.
     *
     * @throws \RuntimeException when tasks or their broadcasts are still pending after $timeoutSeconds
     */
    public function runTasks(float $timeoutSeconds = 5.0): void {
        $via = $this->via;
        $done = true;
        $this->run(static function () use ($via, $timeoutSeconds, &$done): void {
            $done = $via->runTasksInProcess(hrtime(true) + (int) ($timeoutSeconds * 1e9));
        });

        if (!$done) {
            throw new \RuntimeException($via->runningTasks > 0
                ? \sprintf('%d Context::spawn() task(s) still running after %.1f s.', $via->runningTasks, $timeoutSeconds)
                : \sprintf('Broadcasts from tasks still pending after %.1f s.', $timeoutSeconds));
        }
    }

    /**
     * The app's Via, for broadcast(), globalState() and the like.
     */
    public function via(): Via {
        return $this->via;
    }

    /**
     * The scopes passed to Via::broadcast() since the last call, by the app or by a scoped signal's write,
     * one entry per call. A TAB context's Context::broadcast() syncs its tab only and is not one.
     *
     * @return list<string>
     */
    public function broadcasts(): array {
        return $this->via->takeBroadcasts();
    }

    /**
     * The lines php-via logged since the last call, at the Config's log level, while it built the app,
     * served a tab or shut down. A call outside them, such as a broadcast from the test, logs as usual.
     *
     * @return list<string>
     */
    public function logs(): array {
        $logged = $this->logged;
        $this->logged = [];

        return $logged;
    }

    /**
     * Stop as a worker stops: isShuttingDown() turns true, the open streams end (onClientDisconnect runs),
     * then the onWorkerStop callbacks run and every timer is cleared. The tabs take no requests afterwards.
     */
    public function shutdown(): void {
        if ($this->stopped) {
            return;
        }

        try {
            $this->run($this->via->stopInProcess(...));
        } finally {
            $this->stopped = true;
            // As a worker's exit: no timer the app armed fires in a later event loop of this process.
            Timer::clearAll();
        }
    }

    /**
     * Handle $request as the server would.
     *
     * @internal for TestTab
     *
     * @return string what php-via logged meanwhile
     */
    public function send(TestRequest $request, TestResponse $response): string {
        return $this->run(fn () => $this->handler->handleRequest($request, $response));
    }

    /**
     * Send a plain request with $cookies, and keep the cookies its response sets.
     *
     * @internal for TestTab
     *
     * @param array<string, string> $headers
     *
     * @throws \RuntimeException when the response breaks off
     */
    public function fetch(CookieJar $cookies, string $method, string $path, string $body, array $headers): ResponseInterface {
        [$path, $queryString] = explode('?', $path, 2) + [1 => ''];
        parse_str($queryString, $query);
        $response = new TestResponse();
        $this->send(new TestRequest(strtoupper($method), $path, $query, $cookies->all(), array_change_key_case($headers), $body), $response);
        $cookies->take($response);

        if ($response->closed) {
            throw new \RuntimeException(\sprintf('%s %s broke off after %d bytes of its body.', strtoupper($method), $path, \strlen($response->body)));
        }

        return new Psr7Response($response->statusCode, $response->headers, $response->body);
    }

    /**
     * Start the SSE stream of $request in a Fiber of its own, which returns once the stream parks or ends.
     *
     * @internal for TestTab
     *
     * @return \Fiber<mixed, mixed, mixed, mixed>
     */
    public function stream(TestRequest $request, TestResponse $response): \Fiber {
        $handler = $this->handler;
        // Static, so the fiber holds no TestTab and a dropped TestApp still reaches its destructor.
        $fiber = new \Fiber(static fn () => $handler->handleRequest($request, $response));
        $this->run($fiber->start(...));

        return $fiber;
    }

    /**
     * Run $fn as a request is run: with the routes registered so far, and what it logs kept for logs().
     *
     * @internal for TestTab
     *
     * @return string what php-via logged meanwhile
     *
     * @throws \LogicException after shutdown()
     */
    public function run(callable $fn): string {
        if ($this->stopped) {
            throw new \LogicException('This TestApp is shut down.');
        }

        $this->handler->setRoutes($this->via->getRouter()->getRoutes());

        return $this->capture($fn);
    }

    /**
     * The Origin an action sends: the first trusted origin, or the app's own.
     *
     * @internal for TestTab
     */
    public function origin(): string {
        return $this->via->getSettings()->trustedOrigins[0] ?? 'http://' . TestRequest::HOST;
    }

    /**
     * A connection number for a new stream, which SseHandler keys streams by.
     *
     * @internal for TestTab
     */
    public function nextFd(): int {
        return ++$this->lastFd;
    }

    /**
     * @return string what $fn printed, php-via's log lines, which are kept for logs()
     */
    private function capture(callable $fn): string {
        ob_start();

        try {
            $fn();
        } finally {
            $output = (string) ob_get_clean();
            $this->keep($output);
        }

        return $output;
    }

    private function keep(string $output): void {
        foreach (explode("\n", rtrim($output, "\n")) as $line) {
            if ($line !== '') {
                $this->logged[] = $line;
            }
        }
    }
}
