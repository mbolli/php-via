<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Config;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Socket;
use OpenSwoole\Process;
use OpenSwoole\Server;

/**
 * Brotli for static files at the static level (Config::withBrotli()'s $staticLevel), never compressed at that level
 * in a worker: prepare() compresses the files present at start in the master process, a fresh foo.css.br is sent as
 * it is, and any other file goes to a helper process while workers send it at INTERIM_LEVEL or uncompressed.
 * Outside a server (CLI scripts, tests) a file is compressed when first asked for.
 *
 * @internal
 */
final class StaticBrotli {
    /** Starts every message the helper sends a worker. */
    public const string MESSAGE_PREFIX = "\0via-static-br\0";

    /** Time prepare() spends compressing before the server starts listening. */
    public const int BOOT_BUDGET_MS = 2000;

    /** Brotli bodies compressed at start, shared by all workers. */
    public const int BOOT_CACHE_BYTES = 32 << 20;

    /** Brotli bodies each worker keeps of files compressed after start. */
    public const int WORKER_CACHE_BYTES = 16 << 20;

    /** Largest Brotli body kept in memory; a bigger sidecar goes out with sendfile(). */
    public const int BODY_BYTES = 2 << 20;

    /** Largest file compressed at all. */
    public const int SOURCE_BYTES = 8 << 20;

    /** Level and largest file of the stand-in a worker compresses itself while the helper works. */
    public const int INTERIM_LEVEL = 4;

    public const int INTERIM_BYTES = 256 << 10;

    /** Results the helper keeps for the other workers that ask for the same file. */
    private const int HELPER_CACHE_BYTES = 16 << 20;

    private const int JOB_TIMEOUT_S = 60;

    private const int QUEUE_LIMIT = 10_000;

    private const int OK = 1;

    private const int REFUSED = 2;

    private const int CHANGED = 3;

    /** pack() format of a job's head: worker id, mtime, size; the path follows. */
    private const int JOB_HEAD_BYTES = 20;

    /** pack() format of a reply's head: path length, mtime, size, status; the path and the body follow. */
    private const int REPLY_HEAD_BYTES = 21;

    private StaticBodyCache $boot;

    private StaticBodyCache $cache;

    private ?StaticBodyCache $helperResults = null;

    /** @var array<string, string> versions ("mtime:size" by path) the helper refused or this worker cannot keep */
    private array $refused = [];

    /** @var array<string, array{0: int, 1: int}> files waiting for the helper: [mtime, size] by path */
    private array $queue = [];

    /** @var null|array{path: string, mtime: int, size: int, sentAt: int} */
    private ?array $inFlight = null;

    /** @var null|\Closure(string): void sends a job to the helper */
    private ?\Closure $sendJob = null;

    private ?Socket $helperSocket = null;

    private bool $inServer = false;

    private int $workerId = 0;

    /**
     * @param \Closure(string, string): void $log level and message
     */
    public function __construct(private Config $config, private \Closure $log) {
        $this->boot = new StaticBodyCache(self::BOOT_CACHE_BYTES, self::BODY_BYTES);
        $this->cache = new StaticBodyCache(self::WORKER_CACHE_BYTES, self::BODY_BYTES);
    }

    /**
     * Whether static files get Brotli: a static level above 0. Sidecars are sent even without ext-brotli.
     */
    public function enabled(): bool {
        return $this->config->getBrotliStaticLevel() > 0;
    }

    /**
     * The Brotli form to send for this version of a file, or null to send it uncompressed: a body, or a sidecar too
     * big to keep in memory, for sendfile().
     *
     * @param null|string $contents the file's contents, when the caller holds them
     *
     * @return null|array{body: string}|array{file: string}
     */
    public function lookup(string $path, int $mtime, int $size, ?string $contents = null): ?array {
        if (!$this->enabled()) {
            return null;
        }

        $entry = $this->cache->get($path, $mtime, $size);
        if ($entry !== null) {
            if (!$entry['final']) {
                $this->request($path, $mtime, $size);
            }

            return ['body' => $entry['body']];
        }
        $entry = $this->boot->get($path, $mtime, $size);
        if ($entry !== null) {
            return ['body' => $entry['body']];
        }

        $sidecar = $this->sidecar($path, $mtime, $size);
        if ($sidecar !== null) {
            return $sidecar;
        }

        if (!\function_exists('brotli_compress') || $size > self::SOURCE_BYTES || $this->isRefused($path, $mtime, $size)) {
            return null;
        }

        if (!$this->inServer) {
            return $this->compressNow($path, $mtime, $size, $contents, $this->config->getBrotliStaticLevel(), true);
        }

        $this->request($path, $mtime, $size);

        return $size <= self::INTERIM_BYTES ? $this->compressNow($path, $mtime, $size, $contents, self::INTERIM_LEVEL, false) : null;
    }

    /**
     * Set up for a server, in the master process before the workers fork: start the helper process, and outside dev
     * mode compress the files that exist now. The server starts listening only after this.
     *
     * @param list<string> $assets the framework's own files
     */
    public function prepare(Server $server, array $assets, ?string $staticDir): void {
        $this->inServer = true;
        if (!$this->enabled()) {
            return;
        }
        if (!\function_exists('brotli_compress')) {
            ($this->log)('info', 'ext-brotli is not loaded: static files go out uncompressed, except those with a .br sidecar');

            return;
        }

        // Only files in the static dir, or edited in dev mode, change after start.
        if ($staticDir !== null || $this->config->getDevMode()) {
            $process = new Process(function (Process $process) use ($server): void {
                $this->serveHelper($process, $server);
            });
            $server->addProcess($process);
            $this->sendJob = function (string $job) use ($process): void {
                $socket = $this->helperSocket ??= $process->exportSocket();
                // A full pipe suspends the sender, not the request.
                Coroutine::create(static fn () => $socket->send($job));
            };
        }

        if ($this->config->getDevMode()) {
            return;
        }

        $result = $this->precompress($assets, $staticDir);
        if ($result['files'] === 0 && !$result['stopped']) {
            return;
        }
        ($this->log)('info', \sprintf(
            'Brotli level %d: compressed %d static files (%d KB to %d KB) in %d ms before start%s',
            $this->config->getBrotliStaticLevel(),
            $result['files'],
            intdiv($result['bytes'], 1024),
            intdiv($result['compressed'], 1024),
            $result['ms'],
            $result['stopped'] ? \sprintf(
                '; stopped at the %d ms budget, the rest is compressed in the background on first request. '
                . 'Ship .br sidecars to skip this, see https://via.zweiundeins.gmbh/docs/deployment#static-compression',
                self::BOOT_BUDGET_MS,
            ) : '',
        ));
    }

    /**
     * Compress files into the boot cache, the framework's own first, until $budgetMs is used up.
     *
     * @param list<string> $assets
     *
     * @return array{files: int, bytes: int, compressed: int, ms: int, stopped: bool}
     */
    public function precompress(array $assets, ?string $staticDir, int $budgetMs = self::BOOT_BUDGET_MS): array {
        $level = $this->config->getBrotliStaticLevel();
        $start = hrtime(true);
        $deadline = $start + $budgetMs * 1_000_000;
        // Measured at level 11 on JavaScript and CSS: 140 to 170 ms per 100 KB. Refined by each file compressed.
        $nsPerByte = $level >= 10 ? 1_700.0 : 50.0;
        $result = ['files' => 0, 'bytes' => 0, 'compressed' => 0, 'ms' => 0, 'stopped' => false];
        $spentNs = 0;

        $walkCut = false;
        foreach ($this->bootFiles($assets, $staticDir, $deadline, $walkCut) as $path) {
            $now = hrtime(true);
            if ($now >= $deadline) {
                $result['stopped'] = true;

                break;
            }
            clearstatcache(true, $path);
            $stat = self::stat($path);
            if ($stat === null || $stat[1] > self::SOURCE_BYTES || $this->freshSidecar($path, $stat[0]) !== null) {
                continue;
            }
            [$mtime, $size] = $stat;
            if ($now + $size * $nsPerByte > $deadline) {
                $result['stopped'] = true;

                continue;
            }
            $contents = file_get_contents($path);
            if (!\is_string($contents) || \strlen($contents) !== $size) {
                continue;
            }

            $began = hrtime(true);
            $body = brotli_compress($contents, $level, BROTLI_TEXT);
            $spentNs += hrtime(true) - $began;
            $result['bytes'] += $size;
            $nsPerByte = $spentNs / max(1, $result['bytes']);
            if (!\is_string($body) || !$this->boot->put($path, $mtime, $size, $body)) {
                continue;
            }
            ++$result['files'];
            $result['compressed'] += \strlen($body);
        }
        $result['stopped'] = $result['stopped'] || $walkCut;
        $result['ms'] = intdiv(hrtime(true) - $start, 1_000_000);

        return $result;
    }

    /**
     * Mark this as a server worker that sends jobs with $send, as prepare() does with the helper's pipe.
     *
     * @param \Closure(string): void $send
     *
     * @internal for tests
     */
    public function attachHelper(\Closure $send): void {
        $this->inServer = true;
        $this->sendJob = $send;
    }

    public function setWorkerId(int $workerId): void {
        $this->workerId = $workerId;
    }

    /**
     * Take a result the helper sent this worker: a Brotli body, a refusal, or word that the file changed meanwhile.
     */
    public function receive(string $message): void {
        $offset = \strlen(self::MESSAGE_PREFIX);
        if (\strlen($message) < $offset + self::REPLY_HEAD_BYTES) {
            return;
        }
        $head = unpack('Nlength/Jmtime/Jsize/Cstatus', $message, $offset);
        if (!\is_array($head) || \strlen($message) < $offset + self::REPLY_HEAD_BYTES + $head['length']) {
            return;
        }
        $path = substr($message, $offset + self::REPLY_HEAD_BYTES, $head['length']);

        $job = $this->inFlight;
        // A reply to the worker this one replaced after a reload.
        if ($job === null || $job['path'] !== $path || $job['mtime'] !== $head['mtime'] || $job['size'] !== $head['size']) {
            return;
        }
        $this->inFlight = null;

        if ($head['status'] === self::OK) {
            $body = substr($message, $offset + self::REPLY_HEAD_BYTES + $head['length']);
            if (!$this->cache->put($path, $job['mtime'], $job['size'], $body, true, $this->config->getDevMode())) {
                $this->refuse($path, $job['mtime'], $job['size']);
            }
        } elseif ($head['status'] === self::REFUSED) {
            $this->refuse($path, $job['mtime'], $job['size']);
        }
        $this->pump();
    }

    /**
     * The helper's reply to a job: the file compressed at the static level, or why not.
     *
     * @internal public for tests
     */
    public function compressForWorker(string $path, int $mtime, int $size): string {
        $this->helperResults ??= new StaticBodyCache(self::HELPER_CACHE_BYTES, self::BODY_BYTES);
        $done = $this->helperResults->get($path, $mtime, $size);
        if ($done !== null) {
            return self::reply($path, $mtime, $size, self::OK, $done['body']);
        }

        clearstatcache(true, $path);
        if (self::stat($path) !== [$mtime, $size]) {
            return self::reply($path, $mtime, $size, self::CHANGED);
        }
        if ($size > self::SOURCE_BYTES) {
            return self::reply($path, $mtime, $size, self::REFUSED);
        }
        $contents = file_get_contents($path);
        clearstatcache(true, $path);
        if (!\is_string($contents) || \strlen($contents) !== $size || self::stat($path) !== [$mtime, $size]) {
            return self::reply($path, $mtime, $size, self::CHANGED);
        }

        $body = brotli_compress($contents, $this->config->getBrotliStaticLevel(), BROTLI_TEXT);
        if (!\is_string($body) || \strlen($body) > self::BODY_BYTES) {
            return self::reply($path, $mtime, $size, self::REFUSED);
        }
        if (!$this->helperResults->put($path, $mtime, $size, $body)) {
            $this->helperResults = new StaticBodyCache(self::HELPER_CACHE_BYTES, self::BODY_BYTES);
            $this->helperResults->put($path, $mtime, $size, $body);
        }

        return self::reply($path, $mtime, $size, self::OK, $body);
    }

    /**
     * The worker cache, for tests.
     *
     * @internal
     */
    public function workerCache(): StaticBodyCache {
        return $this->cache;
    }

    /**
     * The boot cache, for tests.
     *
     * @internal
     */
    public function bootCache(): StaticBodyCache {
        return $this->boot;
    }

    /**
     * The helper process: compress each file a worker asks for and send the result to that worker. Blocking is fine
     * here, no client waits on this process.
     */
    private function serveHelper(Process $process, Server $server): never {
        while (true) {
            $job = $process->read(65536);
            if (!\is_string($job) || \strlen($job) <= self::JOB_HEAD_BYTES) {
                usleep(100_000);

                continue;
            }
            $head = unpack('Nworker/Jmtime/Jsize', $job);
            if (!\is_array($head)) {
                continue;
            }
            $server->sendMessage($this->compressForWorker(substr($job, self::JOB_HEAD_BYTES), $head['mtime'], $head['size']), $head['worker']);
        }
    }

    /**
     * A file's mtime and size, or null when it is not a file.
     *
     * @return null|array{0: int, 1: int}
     */
    private static function stat(string $path): ?array {
        if (!is_file($path)) {
            return null;
        }
        $mtime = filemtime($path);
        $size = filesize($path);

        return $mtime === false || $size === false ? null : [$mtime, $size];
    }

    private static function reply(string $path, int $mtime, int $size, int $status, string $body = ''): string {
        return self::MESSAGE_PREFIX . pack('NJJC', \strlen($path), $mtime, $size, $status) . $path . $body;
    }

    /**
     * Queue a file for the helper, unless it is queued, being compressed or refused.
     */
    private function request(string $path, int $mtime, int $size): void {
        if ($this->sendJob === null || $this->isRefused($path, $mtime, $size)) {
            return;
        }
        $job = $this->inFlight;
        if ($job !== null && $job['path'] === $path && $job['mtime'] === $mtime && $job['size'] === $size) {
            return;
        }
        if (!isset($this->queue[$path]) && \count($this->queue) >= self::QUEUE_LIMIT) {
            return;
        }
        $this->queue[$path] = [$mtime, $size];
        $this->pump();
    }

    /**
     * Send the next job once the helper answered the last one. One job per worker at a time, so the jobs all workers
     * have in the pipe stay few.
     */
    private function pump(): void {
        if ($this->sendJob === null) {
            return;
        }
        if ($this->inFlight !== null) {
            if (time() - $this->inFlight['sentAt'] < self::JOB_TIMEOUT_S) {
                return;
            }
            ($this->log)('warning', "No Brotli result for {$this->inFlight['path']} after " . self::JOB_TIMEOUT_S . ' s, sending it without');
            $this->refuse($this->inFlight['path'], $this->inFlight['mtime'], $this->inFlight['size']);
            $this->inFlight = null;
        }

        $path = array_key_first($this->queue);
        if ($path === null) {
            return;
        }
        [$mtime, $size] = $this->queue[$path];
        unset($this->queue[$path]);
        $this->inFlight = ['path' => $path, 'mtime' => $mtime, 'size' => $size, 'sentAt' => time()];
        ($this->sendJob)(pack('NJJ', $this->workerId, $mtime, $size) . $path);
    }

    private function isRefused(string $path, int $mtime, int $size): bool {
        return ($this->refused[$path] ?? null) === "{$mtime}:{$size}";
    }

    private function refuse(string $path, int $mtime, int $size): void {
        if (\count($this->refused) >= self::QUEUE_LIMIT) {
            $this->refused = [];
        }
        $this->refused[$path] = "{$mtime}:{$size}";
    }

    /**
     * @return null|array{body: string}
     */
    private function compressNow(string $path, int $mtime, int $size, ?string $contents, int $level, bool $final): ?array {
        $contents ??= is_file($path) ? file_get_contents($path) : false;
        // A file that changed since its stat would not match its ETag.
        if (!\is_string($contents) || \strlen($contents) !== $size) {
            return null;
        }
        // The read may have yielded while the helper's result arrived.
        $done = $this->cache->get($path, $mtime, $size);
        if ($done !== null && $done['final']) {
            return ['body' => $done['body']];
        }
        $body = brotli_compress($contents, $level, BROTLI_TEXT);
        if (!\is_string($body)) {
            return null;
        }
        $this->cache->put($path, $mtime, $size, $body, $final, $this->config->getDevMode());

        return ['body' => $body];
    }

    /**
     * The file's sidecar, kept in memory when it is small enough.
     *
     * @return null|array{body: string}|array{file: string}
     */
    private function sidecar(string $path, int $mtime, int $size): ?array {
        $sidecar = $this->freshSidecar($path, $mtime);
        if ($sidecar === null) {
            return null;
        }
        if ((int) filesize($sidecar) > self::BODY_BYTES) {
            return ['file' => $sidecar];
        }
        $body = file_get_contents($sidecar);
        if (!\is_string($body)) {
            return null;
        }
        $this->cache->put($path, $mtime, $size, $body, true, $this->config->getDevMode());

        return ['body' => $body];
    }

    /**
     * $path.br when it is a file in the same directory, not a link out of it, at least as new as $path.
     */
    private function freshSidecar(string $path, int $mtime): ?string {
        $sidecar = $path . '.br';
        if ($this->config->getDevMode()) {
            clearstatcache(true, $sidecar);
        }
        $sidecarMtime = self::stat($sidecar)[0] ?? null;
        if ($sidecarMtime === null || $sidecarMtime < $mtime) {
            return null;
        }
        $real = realpath($sidecar);

        return $real !== false && \dirname($real) === \dirname($path) ? $real : null;
    }

    /**
     * The framework's files, then the compressible files in the static dir, skipping dot files and dot directories,
     * until $deadline (hrtime) passes, which sets $cut.
     *
     * @param list<string> $assets
     *
     * @return \Generator<int, string>
     */
    private function bootFiles(array $assets, ?string $staticDir, int $deadline, bool &$cut): \Generator {
        yield from $assets;

        $base = $staticDir !== null ? realpath($staticDir) : false;
        if ($base === false) {
            return;
        }

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
                    static fn (string $current): bool => !str_starts_with(basename($current), '.'),
                ),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($files as $file) {
                if (hrtime(true) >= $deadline) {
                    $cut = true;

                    return;
                }
                if (!RequestHandler::staticType((string) $file)[1]) {
                    continue;
                }
                $real = realpath((string) $file);
                if ($real !== false && str_starts_with($real, $base . '/')) {
                    yield $real;
                }
            }
        } catch (\UnexpectedValueException $e) {
            ($this->log)('warning', "Could not walk the static dir for Brotli: {$e->getMessage()}");
        }
    }
}
