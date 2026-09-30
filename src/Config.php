<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\MessageBroker;

/**
 * Configuration class with fluent API.
 */
class Config {
    private string $host = '0.0.0.0';
    private int $port = 3000;
    private bool $devMode = false;
    private string $logLevel = 'info';
    private ?string $templateDir = null;
    private false|string $twigCacheDir = false;
    private ?string $shellTemplate = null;
    private string $basePath = '/';
    private ?string $staticDir = null;

    /** @var null|\Closure(string, string): string|string */
    private \Closure|string|null $staticCacheControl = null;

    /** @var array<string, mixed> */
    private array $openSwooleSettings = [];

    /** Poll interval of the Dev Bar's trace and log stream in milliseconds (default 100 ms). */
    private int $ssePollIntervalMs = 100;

    /** Silence after which an idle page SSE stream sends an SSE comment, in milliseconds; 0 sends none. */
    private int $sseKeepAliveMs = 15_000;

    /**
     * Unsent backlog per SSE connection, in bytes, above which idempotent element
     * frames are dropped for that client instead of parking the coroutine in write().
     * Matches the default socket_buffer_size. 0 disables dropping.
     */
    private int $sseMaxQueuedBytes = 1048576;

    /** Whether broadcasts inside a coroutine are marked and rendered by the worker's next flush. */
    private bool $broadcastCoalescing = true;

    /** Minimum gap between the start or end of one broadcast flush and the start of the next, in ms. */
    private int $broadcastTickMs = 25;

    /**
     * Whether to set the Secure flag on the session cookie (required for HTTPS).
     * Defaults to false so local HTTP dev works out of the box.
     * Set to true in production behind HTTPS.
     */
    private bool $secureCookie = false;

    /**
     * SameSite attribute for the session cookie ('Lax' default, 'None' when embeddable).
     * 'None' is required for the cookie to be sent inside a cross-origin <iframe>.
     */
    private string $sessionCookieSameSite = 'Lax';

    /**
     * Whether to partition the session cookie per top-level site (CHIPS).
     * Lets a SameSite=None cookie survive third-party-cookie phase-out in a cross-site frame.
     */
    private bool $sessionCookiePartitioned = false;

    /**
     * Origins allowed to frame this app (Content-Security-Policy: frame-ancestors).
     * Null means no frame-ancestors restriction is emitted.
     *
     * @var null|list<string>
     */
    private ?array $frameAncestors = null;

    /**
     * Allowed origins for action requests, e.g. ['https://example.com'].
     * Null means the Origin host must match the Host header.
     *
     * @var null|list<string>
     */
    private ?array $trustedOrigins = null;

    /** Accept action requests without an Origin header outside dev mode. */
    private bool $allowMissingOrigin = false;

    /** TAB signals declared without clientWritable are server-owned instead of client-writable. */
    private bool $strictTabSignals = false;

    /** Path to SSL certificate file (PEM). Required for HTTPS/HTTP2. */
    private ?string $sslCertFile = null;

    /** Path to SSL private key file (PEM). Required for HTTPS/HTTP2. */
    private ?string $sslKeyFile = null;

    /**
     * Whether to run HTTP/2 cleartext (h2c) without TLS.
     * Use this when a reverse proxy (Caddy, Nginx) terminates TLS and proxies
     * to this server via h2c. Allows withBrotli() without withCertificate().
     * Only enable when the server is truly behind a trusted TLS-terminating proxy.
     */
    private bool $h2c = false;

    /**
     * Whether to enable Brotli compression for HTTP responses.
     * Requires either withCertificate() (direct HTTPS) or withH2c() (proxy h2c),
     * and the ext-brotli PHP extension. Hard error at start() if either is missing.
     */
    private bool $brotli = false;

    /** Brotli level for dynamic responses (pages, SSE). 0–11; default 4. */
    private int $brotliDynamicLevel = 4;

    /** Brotli level for static assets. 0–11; default 11 (BROTLI_COMPRESS_LEVEL_MAX). */
    private int $brotliStaticLevel = 11;

    /**
     * Number of OpenSwoole worker processes.
     * Worker values > 1 require a multi-worker-capable broker (SwooleBroker, RedisBroker, NatsBroker).
     */
    private int $workerNum = 1;

    private ?string $globalStatePath = null;

    private int $globalStateFlushMs = 1000;

    private int $contextDirectoryRows = 4096;

    private int $contextDirectoryRecordBytes = 1024;

    private int $contextDirectoryTtlSeconds = 3600;

    private int $scopedSignalTableRows = 1024;

    private int $scopedSignalTableValueBytes = 32768;

    /**
     * Maximum number of rows in the GlobalState OpenSwoole\Table.
     * Each row holds one global-state key. Increase if you need more than 1024 distinct keys.
     */
    private int $globalStateTableRows = 1024;

    /**
     * Maximum serialized byte size of a single global-state value.
     * Values exceeding this limit will throw at setGlobalState() time.
     */
    private int $globalStateTableValueBytes = 32768;

    private ?MessageBroker $broker = null;

    /** @var null|callable(\Throwable): void */
    private $brokerErrorHandler;

    /**
     * Maximum action requests per IP per window (0 = unlimited).
     */
    private int $actionRateLimit = 0;

    /**
     * Rate-limit window in seconds.
     */
    private int $actionRateWindow = 60;

    /**
     * Interval in milliseconds between proactive gc_collect_cycles() calls.
     * 0 disables the timer and leaves GC entirely to PHP's automatic trigger.
     */
    private int $gcIntervalMs = 30_000;

    /**
     * Grace period in milliseconds before an inactive context (no live SSE connection) is
     * destroyed, allowing time for page navigation or a brief reconnect. 0 disables the
     * grace period, destroying the context immediately on disconnect.
     */
    private int $contextCleanupDelayMs = 5000;

    /**
     * How long (milliseconds) after a context is destroyed a returning tab may rebuild an
     * equivalent one (same ID, handler re-run, signals re-seeded from the client) instead of
     * hard-reloading. 0 disables revival, falling back to a full page reload on reconnect.
     */
    private int $contextRevivalWindowMs = 600_000;

    /**
     * Whether the Via Dev Bar (tracing overlay + /_via endpoints) is enabled.
     * null = follow devMode; true/false = explicit override.
     */
    private ?bool $tracing = null;

    /**
     * Whether the Dev Bar may write signal state back from the browser.
     * null = follow the VIA_DEVBAR_WRITES env var; true/false = explicit override.
     * Writes are ALWAYS gated behind devMode in addition to this flag.
     */
    private ?bool $tracingWrites = null;

    /** Maximum number of traces retained in the in-process ring buffer. */
    private int $traceBufferSize = 100;

    /** Soft cap on a single serialized trace's byte size (display guard). */
    private int $traceMaxBytes = 16_384;

    public function withHost(string $host): self {
        $this->host = $host;

        return $this;
    }

    public function withPort(int $port): self {
        $this->port = $port;

        return $this;
    }

    public function withDevMode(bool $devMode = true): self {
        $this->devMode = $devMode;

        return $this;
    }

    public function withLogLevel(string $level): self {
        $this->logLevel = $level;

        return $this;
    }

    public function withTemplateDir(string $dir): self {
        $this->templateDir = $dir;

        return $this;
    }

    public function withTwigCacheDir(string $dir): self {
        $this->twigCacheDir = $dir;

        return $this;
    }

    public function getTwigCacheDir(): false|string {
        return $this->twigCacheDir;
    }

    public function withStaticDir(string $dir): self {
        $this->staticDir = rtrim($dir, '/');

        return $this;
    }

    public function getStaticDir(): ?string {
        return $this->staticDir;
    }

    /**
     * Cache-Control header value for file-backed static responses: files served via
     * withStaticDir(), plus the framework's own /datastar.js and /via.css. All three
     * emit ETag/Last-Modified with conditional-GET (304) support regardless of this
     * setting — this only controls the expiry policy on top of that.
     *
     * null (default) = auto: 'no-cache' in devMode (always revalidate, so edits to a
     * withStaticDir() file are visible on the next refresh instead of waiting out a
     * cached max-age), else 'public, max-age=3600, must-revalidate'.
     *
     * Pass a string to apply one Cache-Control value to every static response, e.g.
     * 'public, max-age=31536000, immutable' if you fingerprint filenames yourself.
     *
     * Pass a closure(string $filePath, string $mimeType): string to fine-tune per file —
     * $filePath is the absolute path being served, $mimeType is the resolved MIME type
     * without a charset suffix (e.g. 'text/css', 'image/png'). A string is always taken
     * literally (never invoked as a function name); use first-class callable syntax
     * (`$obj->method(...)`, `SomeClass::method(...)`) to pass an existing method. For
     * example, long-cache fingerprinted assets and fonts, short-cache everything else:
     *
     * ```php
     * $config->withStaticCacheControl(function (string $filePath, string $mimeType): string {
     *     if (preg_match('/\.[0-9a-f]{8,}\./', basename($filePath)) || str_starts_with($mimeType, 'font/')) {
     *         return 'public, max-age=31536000, immutable';
     *     }
     *
     *     return 'public, max-age=3600, must-revalidate';
     * });
     * ```
     *
     * @param null|\Closure(string, string): string|string $value
     */
    public function withStaticCacheControl(\Closure|string|null $value): self {
        $this->staticCacheControl = $value;

        return $this;
    }

    /**
     * @param string $filePath absolute path of the file being served
     * @param string $mimeType resolved MIME type without a charset suffix, e.g. 'text/css'
     */
    public function getStaticCacheControl(string $filePath, string $mimeType): string {
        if ($this->staticCacheControl instanceof \Closure) {
            return ($this->staticCacheControl)($filePath, $mimeType);
        }

        if ($this->staticCacheControl !== null) {
            return $this->staticCacheControl;
        }

        return $this->devMode ? 'no-cache' : 'public, max-age=3600, must-revalidate';
    }

    public function withShellTemplate(string $path): self {
        $this->shellTemplate = $path;

        return $this;
    }

    /**
     * Set the URL base path prefix (e.g. '/app/' when mounted at a sub-path).
     * Must be a relative path: '/', '/app', '/sub/path', etc.
     *
     * @throws \InvalidArgumentException if the value is not a valid relative path
     */
    public function withBasePath(string $basePath): self {
        // Accept only safe relative paths: zero or more /segment components
        // (each starting with [a-zA-Z0-9]) followed by an optional trailing slash.
        // Rejects protocol-relative paths (//evil.com), absolute URLs (https://…),
        // backslashes, and any other unexpected characters.
        if (!preg_match('#^(?:/[a-zA-Z0-9][a-zA-Z0-9_.-]*)*/?$#', $basePath)) {
            throw new \InvalidArgumentException(
                "Invalid basePath '{$basePath}': must be a relative path like '/', '/app', or '/sub/path'."
            );
        }

        $this->basePath = rtrim($basePath, '/') . '/';

        return $this;
    }

    /**
     * How often the Dev Bar's trace and log stream checks for new records (default 100 ms).
     *
     * Page SSE streams do not poll: a stream wakes when a patch is queued for it, when its
     * connection closes and when the worker stops. See withSseKeepAliveMs().
     */
    public function withSsePollIntervalMs(int $ms): self {
        $this->ssePollIntervalMs = max(1, $ms);

        return $this;
    }

    public function getSsePollIntervalMs(): int {
        return $this->ssePollIntervalMs;
    }

    /**
     * Set how long a page SSE stream may stay silent before it sends an SSE comment (default 15 s).
     *
     * The comment keeps a proxy's idle timeout, such as nginx's 60 s proxy_read_timeout, from
     * cutting streams that have nothing to send. Browsers and Datastar ignore it. Every idle
     * stream wakes once per interval, also to check that its connection still exists, so values
     * below 1000 cost CPU when many clients are connected.
     *
     * @param int $ms interval in milliseconds; 0 sends no comment, and idle streams then wake once a minute
     */
    public function withSseKeepAliveMs(int $ms): self {
        $this->sseKeepAliveMs = max(0, $ms);

        return $this;
    }

    public function getSseKeepAliveMs(): int {
        return $this->sseKeepAliveMs;
    }

    /**
     * Set the per-connection unsent-backlog threshold for dropping element frames.
     *
     * A slow client otherwise parks its SSE coroutine inside write() until it drains
     * or disconnects — measured at 20s — during which that connection stops observing
     * shutdown and disconnect. Element patches are idempotent, so a backed-up client
     * catches up on the next broadcast. Signals and scripts are never dropped.
     *
     * @param int $bytes threshold in bytes; 0 or less disables dropping entirely
     */
    public function withSseMaxQueuedBytes(int $bytes): self {
        $this->sseMaxQueuedBytes = $bytes;

        return $this;
    }

    public function getSseMaxQueuedBytes(): int {
        return $this->sseMaxQueuedBytes;
    }

    /**
     * Coalesce broadcasts into one flush per worker (on by default).
     *
     * Inside a coroutine, broadcast(), scoped signal writes and broadcasts received from other
     * workers or nodes then only mark the scope. The worker's next flush renders each marked
     * scope once, renders a context in several marked scopes once, and publishes each scope to
     * the broker once. See withBroadcastTickMs() for when a flush runs. Views render the state
     * as it is at flush time, and patches an action queues itself (execScript(), sync()) reach
     * the client before the broadcast's frame; call Via::flushBroadcasts() where that order
     * matters. Outside a coroutine and during shutdown broadcast() stays synchronous.
     *
     * @param bool $enabled false renders and publishes synchronously on every call, as earlier releases did
     */
    public function withBroadcastCoalescing(bool $enabled = true): self {
        $this->broadcastCoalescing = $enabled;

        return $this;
    }

    public function isBroadcastCoalescingEnabled(): bool {
        return $this->broadcastCoalescing;
    }

    /**
     * Set the broadcast tick: the minimum gap between two flushes of one worker (default 25 ms).
     *
     * A broadcast on a worker whose last flush started or ended at least this long ago is
     * flushed at the end of the current event-loop turn, so an idle server adds no delay. Under
     * load, a flush starts this long after the previous one started or ended (OpenSwoole timers
     * resolve to 1 ms), so flushes whose views render for F ms without waiting on I/O take at
     * most F / (F + tick) of a worker, however many actions arrive, and every broadcast in
     * between shares the next flush. A flush does not wait for another scope's flush that waits
     * on I/O. Larger values cost latency under load and save CPU.
     * Without coalescing (withBroadcastCoalescing(false)) there is no tick.
     *
     * @param int $ms gap in milliseconds; 0 flushes at the end of every event-loop turn with no gap
     */
    public function withBroadcastTickMs(int $ms): self {
        $this->broadcastTickMs = max(0, $ms);

        return $this;
    }

    public function getBroadcastTickMs(): int {
        return $this->broadcastTickMs;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function withSwooleSettings(array $settings): self {
        $this->openSwooleSettings = array_merge($this->openSwooleSettings, $settings);

        return $this;
    }

    public function getHost(): string {
        return $this->host;
    }

    public function getPort(): int {
        return $this->port;
    }

    public function getDevMode(): bool {
        return $this->devMode;
    }

    public function getLogLevel(): string {
        return $this->logLevel;
    }

    public function getTemplateDir(): ?string {
        return $this->templateDir;
    }

    public function getShellTemplate(): ?string {
        return $this->shellTemplate;
    }

    public function getBasePath(): string {
        return $this->basePath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSwooleSettings(): array {
        return $this->openSwooleSettings;
    }

    /**
     * Require the Secure cookie attribute.
     * Enable this for any deployment served over HTTPS.
     */
    public function withSecureCookie(bool $secure = true): self {
        $this->secureCookie = $secure;

        return $this;
    }

    public function getSecureCookie(): bool {
        return $this->secureCookie;
    }

    /**
     * Make this app safe to embed in a cross-origin <iframe>.
     *
     * Sets the session cookie to SameSite=None; Secure (+ Partitioned/CHIPS) so the browser
     * sends it inside a cross-site frame — required for the SSE session-auth gate to pass.
     * Optionally emits Content-Security-Policy: frame-ancestors to restrict who may frame the app.
     *
     * Implies withSecureCookie(true). Requires HTTPS (withCertificate) or h2c (withH2c): a
     * SameSite=None cookie without Secure is dropped by browsers, so start() hard-errors otherwise.
     *
     * Note: $frameAncestors restricts who may FRAME the app (CSP). It is unrelated to
     * withTrustedOrigins(), which allowlists the action POST Origin (always the app's own origin).
     *
     * @param null|array<string>|string $frameAncestors origins allowed to frame this app,
     *                                                  e.g. 'https://mbolli.github.io'. null = do not emit a frame-ancestors restriction.
     * @param bool                      $partitioned    partition the cookie per top-level site (CHIPS). Recommended true.
     */
    public function withEmbeddable(array|string|null $frameAncestors = null, bool $partitioned = true): self {
        $this->sessionCookieSameSite = 'None';
        $this->secureCookie = true;              // SameSite=None requires Secure
        $this->sessionCookiePartitioned = $partitioned;
        if ($frameAncestors !== null) {
            $this->frameAncestors = array_values(array_map(strval(...), (array) $frameAncestors));
        }

        return $this;
    }

    public function getSessionCookieSameSite(): string {
        return $this->sessionCookieSameSite;
    }

    public function isSessionCookiePartitioned(): bool {
        return $this->sessionCookiePartitioned;
    }

    /**
     * @return null|list<string>
     */
    public function getFrameAncestors(): ?array {
        return $this->frameAncestors;
    }

    /**
     * Restrict action POST requests to the given list of Origin header values.
     *
     * Each entry should be a full origin without trailing slash, e.g. 'https://example.com'.
     * With null (the default) the Origin host must match the Host header instead. A request
     * without Origin is denied outside dev mode either way, see withAllowMissingOrigin().
     *
     * @param null|list<string> $origins
     */
    public function withTrustedOrigins(?array $origins): self {
        $this->trustedOrigins = $origins;

        return $this;
    }

    /**
     * @return null|list<string>
     */
    public function getTrustedOrigins(): ?array {
        return $this->trustedOrigins;
    }

    /**
     * Accept action requests that carry no Origin header outside dev mode.
     *
     * Browsers send Origin on every POST, so only non-browser clients (curl, server-to-server
     * calls, uptime checks) need this. Off by default: such requests get 403 in production,
     * with or without withTrustedOrigins(). Dev mode always accepts them. Also applies to the
     * Dev Bar's /_via/signal and /_via/reset.
     */
    public function withAllowMissingOrigin(bool $allow = true): self {
        $this->allowMissingOrigin = $allow;

        return $this;
    }

    public function getAllowMissingOrigin(): bool {
        return $this->allowMissingOrigin;
    }

    /**
     * Make TAB signals server-owned unless declared with clientWritable: true.
     *
     * Off by default: a TAB signal declared without clientWritable accepts the value the browser
     * posts with every action. In strict mode the server ignores that value, so only signals the
     * page binds or assigns in the browser need clientWritable: true. An explicit
     * clientWritable: false is honoured in either mode. Revival does not restore server-owned
     * signals from the browser either, so they start from the handler's initial value. With
     * worker_num > 1 that rebuild also happens on every action another worker takes, so
     * server-owned TAB signals are per-worker state: use worker_num = 1 or a scoped signal.
     */
    public function withStrictTabSignals(bool $strict = true): self {
        $this->strictTabSignals = $strict;

        return $this;
    }

    public function getStrictTabSignals(): bool {
        return $this->strictTabSignals;
    }

    /**
     * Rate-limit action requests per IP.
     *
     * @param int $maxRequests   Maximum requests per window (0 = no limit)
     * @param int $windowSeconds Window size in seconds (default 60)
     */
    public function withActionRateLimit(int $maxRequests, int $windowSeconds = 60): self {
        $this->actionRateLimit = max(0, $maxRequests);
        $this->actionRateWindow = max(1, $windowSeconds);

        return $this;
    }

    public function getActionRateLimit(): int {
        return $this->actionRateLimit;
    }

    public function getActionRateWindow(): int {
        return $this->actionRateWindow;
    }

    /**
     * Configure the proactive GC timer interval.
     *
     * php-via runs as a persistent process; PHP's cycle collector only fires when
     * its internal root buffer fills (~10,000 new roots), which can cause sudden
     * micro-pauses under load. Calling gc_collect_cycles() on a fixed timer spreads
     * that work out predictably during idle gaps between requests.
     *
     * @param int $ms Timer interval in milliseconds. Pass 0 to disable.
     */
    public function withGcInterval(int $ms): self {
        $this->gcIntervalMs = max(0, $ms);

        return $this;
    }

    public function getGcIntervalMs(): int {
        return $this->gcIntervalMs;
    }

    /**
     * Configure the context cleanup grace period.
     *
     * When an SSE stream disconnects, php-via doesn't destroy the context immediately —
     * it waits this long for a page navigation or reconnect before tearing it down. Longer
     * delays tolerate flakier clients at the cost of holding idle contexts (and their
     * in-memory view payloads) in memory for longer under concurrent disconnects.
     *
     * @param int $ms Grace period in milliseconds. Pass 0 to disable (cleanup is immediate).
     */
    public function withContextCleanupDelay(int $ms): self {
        $this->contextCleanupDelayMs = max(0, $ms);

        return $this;
    }

    public function getContextCleanupDelayMs(): int {
        return $this->contextCleanupDelayMs;
    }

    /**
     * Configure the context revival window.
     *
     * When a tab is backgrounded long enough that its context is destroyed (past
     * {@see withContextCleanupDelay()}), a returning tab normally hard-reloads. With revival
     * enabled, the server instead rebuilds an equivalent context — same ID, so the already-loaded
     * DOM keeps working — by re-running the page handler and re-seeding signal values the client
     * still holds. This preserves local (underscore) signals, scroll, and focus that a reload
     * would destroy. Revival re-runs the page handler, so it is not lossless: server-only state
     * (e.g. #[Persist]) resets and onDisconnect/connect hooks re-fire, exactly as on a reload.
     *
     * @param int $ms Window in milliseconds. Pass 0 to disable (reconnect falls back to a reload).
     */
    public function withContextRevivalWindow(int $ms): self {
        $this->contextRevivalWindowMs = max(0, $ms);

        return $this;
    }

    public function getContextRevivalWindowMs(): int {
        return $this->contextRevivalWindowMs;
    }

    /**
     * Enable HTTPS by providing paths to the SSL certificate and private key files.
     * Also enables HTTP/2 automatically (open_http2_protocol).
     *
     * @param string $certFile Path to PEM certificate file
     * @param string $keyFile  Path to PEM private key file
     */
    public function withCertificate(string $certFile, string $keyFile): self {
        $this->sslCertFile = $certFile;
        $this->sslKeyFile = $keyFile;

        return $this;
    }

    public function getSslCertFile(): ?string {
        return $this->sslCertFile;
    }

    public function getSslKeyFile(): ?string {
        return $this->sslKeyFile;
    }

    /**
     * Returns true if SSL certificate and key have been configured.
     */
    public function isHttps(): bool {
        return $this->sslCertFile !== null && $this->sslKeyFile !== null;
    }

    /**
     * Enable Brotli compression for HTTP responses (pages, static assets, SSE streams).
     * Requires withCertificate() (direct HTTPS) or withH2c() (proxy h2c), and ext-brotli.
     * A hard error is thrown at start() if either requirement is not met.
     *
     * @param bool $enabled      enable or disable Brotli compression
     * @param int  $dynamicLevel Compression level for pages and SSE (0–11). Default 4 — fast,
     *                           low CPU overhead on the hot path.
     * @param int  $staticLevel  Compression level for static assets (0–11). Default 11 — maximum
     *                           ratio; paid once per file then served from an in-memory cache.
     */
    /**
     * Enable Brotli compression for pages, static assets and the SSE stream.
     *
     * **The dynamic level is a memory decision, not just a bandwidth one.** A streaming Brotli
     * encoder holds per-connection state that grows toward the window cap as the stream feeds it
     * — lazily (7 KB at init) but saturating after ~8 MB of traffic, and it never shrinks.
     * Measured on real 127 KB Game-of-Life SSE frames, ext-brotli 0.21.0:
     *
     *   level | per encoder | at 2,000 conns |   ratio | CPU per frame
     *       1 |      574 KB |         1.1 GB |  19.0:1 |      90 us
     *       2 |     8573 KB |        16.4 GB |  26.9:1 |     190 us
     *       3 |     8567 KB |        16.3 GB |  27.8:1 |     218 us
     *       4 |     8851 KB |        16.9 GB |  30.3:1 |     277 us   <- default
     *       5 |     9677 KB |        18.5 GB |  40.3:1 |     450 us
     *       8 |    13577 KB |        25.9 GB |  49.5:1 |    1094 us
     *      11 |    31474 KB |        60.0 GB |  75.3:1 |   94551 us
     *
     * So the default costs ~8.9 MB per *busy* long-lived stream. That is fine for hundreds of
     * connections and ruinous for thousands: pick level 1 when connection count matters more
     * than egress (15x less memory, 3x less CPU, 37% more bytes on the wire), and raise the
     * level only when streams are few or low-volume.
     *
     * Note the cost is driven by traffic, not connections: an idle stream stays near 7 KB. A
     * counter that ticks occasionally never approaches these numbers; a Game-of-Life board does.
     *
     * The sliding window itself is NOT tunable. ext-brotli's brotli_compress_init() takes only
     * (level, mode, dict) and hardcodes BROTLI_DEFAULT_WINDOW (22 = 4 MB), so the window cannot
     * be lowered to save memory or raised for the compression Anders Murphy reports from larger
     * windows. Changing that needs an upstream extension change.
     *
     * $staticLevel applies to one-shot asset compression, which is cached per file+mtime, so its
     * 94 ms at level 11 is paid once rather than per request.
     */
    public function withBrotli(bool $enabled = true, int $dynamicLevel = 4, int $staticLevel = 11): self {
        $this->brotli = $enabled;
        $this->brotliDynamicLevel = max(0, min(11, $dynamicLevel));
        $this->brotliStaticLevel = max(0, min(11, $staticLevel));

        return $this;
    }

    public function getBrotli(): bool {
        return $this->brotli;
    }

    public function getBrotliDynamicLevel(): int {
        return $this->brotliDynamicLevel;
    }

    public function getBrotliStaticLevel(): int {
        return $this->brotliStaticLevel;
    }

    /**
     * Enable HTTP/2 cleartext (h2c) mode for use behind a TLS-terminating reverse proxy.
     *
     * Use this when Caddy or Nginx handles TLS certs and proxies to OpenSwoole via h2c
     * (e.g. `reverse_proxy h2c://localhost:3000` in Caddy). Satisfies the withBrotli()
     * HTTPS requirement without needing withCertificate().
     *
     * Do NOT enable on a server exposed directly to untrusted traffic.
     */
    public function withH2c(bool $enabled = true): self {
        $this->h2c = $enabled;

        return $this;
    }

    public function isH2c(): bool {
        return $this->h2c;
    }

    /**
     * Set the message broker for multi-node broadcasting.
     *
     * A broker enables broadcast() to reach contexts on other nodes (workers,
     * servers, containers). The default InMemoryBroker is a no-op suitable for
     * single-node deployments.
     *
     * Example:
     * ```php
     * (new Config())->withBroker(new RedisBroker('127.0.0.1', 6379))
     * ```
     */
    public function withBroker(MessageBroker $broker): self {
        $this->broker = $broker;

        return $this;
    }

    /**
     * Register a callable to be invoked when the broker loses its connection and
     * is attempting to reconnect. Use this to log alerts or expose health metrics.
     *
     * The callable receives the \Throwable that caused the drop.
     *
     * **Security note:** Do NOT log `$e->getMessage()` verbatim in production if the
     * message may contain connection strings, auth tokens, or hostnames that should
     * not appear in log files. Log a safe summary or use a structured logger with
     * redaction.
     *
     * Example:
     * ```php
     * (new Config())
     *     ->withBroker(new RedisBroker('127.0.0.1', 6379))
     *     ->onBrokerError(fn (\Throwable $e) => $logger->error('Broker error: ' . $e->getMessage()))
     * ```
     *
     * @param callable(\Throwable): void $handler
     */
    public function onBrokerError(callable $handler): self {
        $this->brokerErrorHandler = $handler;

        return $this;
    }

    /**
     * Return the configured broker error handler, or null if none was set.
     *
     * @return null|callable(\Throwable): void
     */
    public function getBrokerErrorHandler(): ?callable {
        return $this->brokerErrorHandler;
    }

    /**
     * Return the configured broker, or a no-op InMemoryBroker if none was set.
     */
    public function getBroker(): MessageBroker {
        return $this->broker ?? new InMemoryBroker();
    }

    /**
     * Set the number of OpenSwoole worker processes.
     *
     * Using more than one worker distributes CPU-bound actions across cores.
     * Requires a multi-worker-capable broker: SwooleBroker (same machine),
     * RedisBroker or NatsBroker (multi-server). A RuntimeException is thrown at
     * start() if worker_num > 1 and InMemoryBroker is still in use.
     *
     * Session data is NOT shared across workers — use a sticky-session load
     * balancer when running multi-worker (e.g. Caddy sticky_cookie).
     *
     * Example:
     * ```php
     * (new Config())
     *     ->withWorkerNum(swoole_cpu_num())
     *     ->withBroker(new SwooleBroker())
     * ```
     */
    public function withWorkerNum(int $n): self {
        $this->workerNum = max(1, $n);

        return $this;
    }

    public function getWorkerNum(): int {
        return $this->workerNum;
    }

    /**
     * Tune the OpenSwoole\Table that backs GlobalState in multi-worker mode.
     *
     * $maxRows is a FLOOR, not a ceiling. OpenSwoole rounds the allocation up (power of two,
     * floor 64) and then admits well past it — 1024 rows takes ~1776 keys, 4096 takes ~8043 —
     * after which keys are rejected by hash, intermittently, with no eviction. Size for the key
     * count you need and treat anything above $maxRows as headroom you cannot rely on. Exceeding
     * it raises \OverflowException from GlobalState writes.
     *
     * Integer values are stored in a dedicated atomic column and ignore $maxValueBytes (1024
     * counters measured at +0.1 MB); everything else is PHP-serialized and must fit within it.
     *
     *
     * The byte cap costs nothing until it is used. OpenSwoole maps the table lazily, so the
     * nominal size is not resident memory: a 1024-row table costs a flat ~8 MB whether the value
     * column is 4 KB or 64 KB, and grows only as rows are actually written with large values
     * (1024 full 64 KB rows measured at +60 MB, 1024 full 4 KB rows at +0.1 MB). The cap is
     * therefore a guardrail against a runaway value, not a memory budget — raise it freely for
     * values you intend to store.
     *
     * @param int $maxRows       Guaranteed number of distinct global-state keys (default 1024)
     * @param int $maxValueBytes Maximum serialized byte size per value (default 32768)
     */
    public function withGlobalStateTableSize(int $maxRows, int $maxValueBytes = 32768): self {
        $this->globalStateTableRows = max(1, $maxRows);
        $this->globalStateTableValueBytes = max(64, $maxValueBytes);

        return $this;
    }

    /**
     * Tune the OpenSwoole\Table that backs scoped signal VALUES in multi-worker mode.
     *
     * One row per distinct scoped (non-TAB) signal. As with withGlobalStateTableSize(),
     * $maxRows is a floor rather than a ceiling — size for the count you need.
     *
     * Integer signals are stored in a dedicated atomic column and ignore $maxValueBytes;
     * everything else is PHP-serialized and must fit within it.
     *
     *
     * The byte cap costs nothing until it is used. OpenSwoole maps the table lazily, so the
     * nominal size is not resident memory: a 1024-row table costs a flat ~8 MB whether the value
     * column is 4 KB or 64 KB, and grows only as rows are actually written with large values
     * (1024 full 64 KB rows measured at +60 MB, 1024 full 4 KB rows at +0.1 MB). The cap is
     * therefore a guardrail against a runaway value, not a memory budget — raise it freely for
     * values you intend to store.
     *
     * @param int $maxRows       Guaranteed number of distinct scoped signals (default 1024)
     * @param int $maxValueBytes Maximum serialized byte size per non-integer value (default 32768)
     */
    public function withScopedSignalTableSize(int $maxRows, int $maxValueBytes = 32768): self {
        $this->scopedSignalTableRows = max(1, $maxRows);
        $this->scopedSignalTableValueBytes = max(64, $maxValueBytes);

        return $this;
    }

    /**
     * Tune the cross-worker context directory used in multi-worker mode.
     *
     * One row per live (or recently destroyed) context across all workers, so size it for peak
     * concurrent tabs. As with the other shared tables, $maxRows is a floor rather than a
     * ceiling. Overflow is logged, not thrown: the affected context simply loses cross-worker
     * reachability and its actions fall back to HTTP 400.
     *
     * $ttlSeconds bounds how long a record survives without a heartbeat. Every worker rewrites
     * the records of the contexts it streams to every quarter of $ttlSeconds or of the revival
     * window, whichever is shorter, so this only governs entries left behind by a crashed worker.
     *
     * @param int $maxRows        Guaranteed number of tracked contexts (default 4096)
     * @param int $maxRecordBytes Serialized bytes per record (default 1024; real records are 92-341)
     * @param int $ttlSeconds     Expiry for a record with no heartbeat (default 3600)
     */
    public function withContextDirectorySize(int $maxRows, int $maxRecordBytes = 1024, int $ttlSeconds = 3600): self {
        $this->contextDirectoryRows = max(1, $maxRows);
        $this->contextDirectoryRecordBytes = max(128, $maxRecordBytes);
        $this->contextDirectoryTtlSeconds = max(60, $ttlSeconds);

        return $this;
    }

    public function getContextDirectoryRows(): int {
        return $this->contextDirectoryRows;
    }

    public function getContextDirectoryRecordBytes(): int {
        return $this->contextDirectoryRecordBytes;
    }

    public function getContextDirectoryTtlSeconds(): int {
        return $this->contextDirectoryTtlSeconds;
    }

    public function getScopedSignalTableRows(): int {
        return $this->scopedSignalTableRows;
    }

    public function getScopedSignalTableValueBytes(): int {
        return $this->scopedSignalTableValueBytes;
    }

    /**
     * Persist GlobalState to a SQLite file so it survives a server restart.
     *
     * Reads never touch SQLite. Writes land in shared memory at full speed and set a dirty flag;
     * a timer on the leader worker drains the dirty set into one batched transaction. Measured
     * flush cost is ~1 us per changed key (100 keys in 102 us), plus a sub-millisecond WAL
     * checkpoint every few thousand writes — versus 2.8 us on EVERY read if SQLite sat in front
     * instead, each one non-yielding CPU that stalls the whole worker's event loop.
     *
     * The trade is a bounded loss window: anything written since the last flush is lost if the
     * process dies. Lower $flushMs to narrow it, at the cost of more frequent (but still
     * sub-millisecond) stalls.
     *
     * Enabling this also allocates the shared table in single-worker mode, so that GlobalState
     * has somewhere to be dirty-tracked. Reads stay at ~0.19 us.
     *
     * @param string $path    SQLite file to persist to; created if absent
     * @param int    $flushMs How often the leader worker drains the dirty set (default 1000)
     */
    public function withPersistentGlobalState(string $path, int $flushMs = 1000): self {
        $this->globalStatePath = $path;
        $this->globalStateFlushMs = max(50, $flushMs);

        return $this;
    }

    public function getGlobalStatePath(): ?string {
        return $this->globalStatePath;
    }

    public function getGlobalStateFlushMs(): int {
        return $this->globalStateFlushMs;
    }

    public function getGlobalStateTableRows(): int {
        return $this->globalStateTableRows;
    }

    public function getGlobalStateTableValueBytes(): int {
        return $this->globalStateTableValueBytes;
    }

    /**
     * Enable the Via Dev Bar: a tabbed debug overlay (traces, signals, SSE
     * patches, request, scopes, errors) injected into every page, plus the
     * `/_via/*` endpoints and standalone console.
     *
     * Like `/_stats`, the Dev Bar exposes timings, routes, and live signal
     * state — it is for development. It defaults to `getDevMode()`, but you may
     * force it on (e.g. to demo it on a public site) by passing `true`, or off
     * with `false`. Even when forced on, signal *editing* stays disabled unless
     * devMode is also on (see {@see withTracingWrites()}).
     *
     * @param null|bool $enabled true/false to force, null to follow devMode
     */
    public function withTracing(?bool $enabled = true): self {
        $this->tracing = $enabled;

        return $this;
    }

    public function isTracingEnabled(): bool {
        return $this->tracing ?? $this->devMode;
    }

    /**
     * Allow the Dev Bar's Signals panel to write values back to the server.
     *
     * **Hard production guard:** writes require `devMode` *in addition to* this
     * flag and tracing being enabled. The leading devMode check means an
     * explicit `withTracingWrites(true)` is ignored when devMode is off — so
     * `withTracing(true)` on a public site is always read-only. Editing is
     * opt-in for local dev via this call or the `VIA_DEVBAR_WRITES=1` env var.
     *
     * The abuse surface is real: any visitor who can reach the page could
     * mutate ROUTE/SESSION/GLOBAL scope state shared with other users. Never
     * enable this on a deployment exposed to untrusted traffic.
     *
     * @param null|bool $enabled true/false to force, null to follow VIA_DEVBAR_WRITES
     */
    public function withTracingWrites(?bool $enabled = null): self {
        $this->tracingWrites = $enabled;

        return $this;
    }

    public function isTracingWritesEnabled(): bool {
        if (!$this->devMode || !$this->isTracingEnabled()) {
            return false;
        }

        if ($this->tracingWrites !== null) {
            return $this->tracingWrites;
        }

        $env = getenv('VIA_DEVBAR_WRITES');

        return $env === '1' || $env === 'true';
    }

    /**
     * Tune the trace ring buffer.
     *
     * @param int $traces        Maximum traces retained (default 100)
     * @param int $maxTraceBytes Soft cap on a serialized trace's size (default 16384)
     */
    public function withTraceBufferSize(int $traces = 100, int $maxTraceBytes = 16_384): self {
        $this->traceBufferSize = max(1, $traces);
        $this->traceMaxBytes = max(1024, $maxTraceBytes);

        return $this;
    }

    public function getTraceBufferSize(): int {
        return $this->traceBufferSize;
    }

    public function getTraceMaxBytes(): int {
        return $this->traceMaxBytes;
    }
}
