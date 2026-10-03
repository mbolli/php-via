<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Support\Logger;
use OpenSwoole\Http\Response;

/**
 * The one-shot downloads of Context::download(): a token per download, bound to its page context and session.
 *
 * @internal used by Context and RequestHandler
 */
final class DownloadHandler {
    /** Where downloads are served, after the base path. */
    public const string PATH = '_download/';

    /** Downloads one page keeps; a new one past this drops its oldest. */
    public const int MAX_PER_PAGE = 100;

    /** @var array<string, array{page: \WeakReference<Context>, source: callable|string, filename: string, mimeType: string}> Downloads by token */
    private array $downloads = [];

    /** @var \WeakMap<Context, array<string, true>> The tokens of each page */
    private \WeakMap $tokensByPage;

    public function __construct(private Logger $logger) {
        $this->tokensByPage = new \WeakMap();
    }

    /**
     * Keep a download for $page until it is fetched, the page is destroyed, or the page has MAX_PER_PAGE newer ones.
     *
     * @param callable(): (iterable<string>|string)|string $source a file path, or a callable that returns or yields the content
     *
     * @return string the token
     *
     * @throws \InvalidArgumentException see Context::download()
     */
    public function register(Context $page, callable|string $source, string $filename, string $mimeType): string {
        if (\is_string($source) && (!is_file($source) || !is_readable($source))) {
            throw new \InvalidArgumentException("download() takes a string as the path of a file to send, and '{$source}' is no readable file. For content in a string, pass fn () => \$content.");
        }
        if ($filename === '' || !mb_check_encoding($filename, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $filename) === 1) {
            throw new \InvalidArgumentException('download() needs a filename in UTF-8 without control characters, got ' . var_export($filename, true) . '.');
        }
        if (preg_match('#^[\w.+-]+/[\w.+-]+(?:\s*;\s*[\w.+-]+=(?:[\w.+-]+|"[^"\\\\\x00-\x1F\x7F]*"))*$#', $mimeType) !== 1) {
            throw new \InvalidArgumentException("download() needs a MIME type such as 'text/csv; charset=utf-8', got " . var_export($mimeType, true) . '.');
        }

        if ($page->isDestroyed()) {
            // Its onCleanup() callbacks ran already, so nothing would drop it: a URL that answers 404, as once they ran.
            return bin2hex(random_bytes(16));
        }

        if (!isset($this->tokensByPage[$page])) {
            $this->tokensByPage[$page] = [];
            $page->onCleanup($this->forget(...));
        }

        $token = bin2hex(random_bytes(16));
        $this->downloads[$token] = ['page' => \WeakReference::create($page), 'source' => $source, 'filename' => $filename, 'mimeType' => $mimeType];
        $tokens = $this->tokensByPage[$page];
        $tokens[$token] = true;
        if (\count($tokens) > self::MAX_PER_PAGE) {
            $oldest = array_key_first($tokens);
            unset($tokens[$oldest], $this->downloads[$oldest]);
        }
        $this->tokensByPage[$page] = $tokens;

        return $token;
    }

    /**
     * Drop every download of $page.
     */
    public function forget(Context $page): void {
        foreach ($this->tokensByPage[$page] ?? [] as $token => $_) {
            unset($this->downloads[$token]);
        }
        unset($this->tokensByPage[$page]);
    }

    /**
     * Answer a request for a download: 404 for an unknown, used or expired token, 403 for another session,
     * which leaves the download to its own session, and otherwise the content, after which the token is gone.
     *
     * @param string                                   $sessionId the requester's session
     * @param null|\Closure(\Throwable, Context): void $onError   sees a source's throw, after it is logged
     *
     * @return int the status sent
     */
    public function send(Response $response, string $token, string $sessionId, ?\Closure $onError = null): int {
        $download = $this->downloads[$token] ?? null;
        $page = $download === null ? null : $download['page']->get();
        if ($download === null || $page === null) {
            $response->status(404);
            $response->end('Not Found');

            return 404;
        }

        $owner = $page->getSessionId();
        if ($owner !== null && $owner !== $sessionId) {
            $response->status(403);
            $response->end('Forbidden');

            return 403;
        }

        unset($this->downloads[$token]);
        $tokens = $this->tokensByPage[$page] ?? [];
        unset($tokens[$token]);
        $this->tokensByPage[$page] = $tokens;

        $source = $download['source'];
        if (\is_string($source)) {
            if (!is_file($source)) {
                $response->status(404);
                $response->end('Not Found');

                return 404;
            }
            self::sendHeaders($response, $download['filename'], $download['mimeType']);
            $response->sendfile($source);

            return 200;
        }

        return $this->stream($response, $source, $download['filename'], $download['mimeType'], $page, $onError);
    }

    /**
     * Send what the callable returns or yields. Headers go out with the first chunk, so a callable that throws
     * before it gets a 500; one that throws later has its connection closed, so the browser sees the download fail.
     *
     * @param callable(): mixed                        $source  checked here, since what it returns or yields is not
     * @param null|\Closure(\Throwable, Context): void $onError
     */
    private function stream(Response $response, callable $source, string $filename, string $mimeType, Context $page, ?\Closure $onError): int {
        $started = false;

        try {
            $content = $source();
            if (\is_string($content)) {
                self::sendHeaders($response, $filename, $mimeType);
                $response->end($content);

                return 200;
            }
            if (!is_iterable($content)) {
                throw new \UnexpectedValueException('A download source returns a string or yields strings, got ' . get_debug_type($content));
            }

            foreach ($content as $chunk) {
                if (!\is_string($chunk)) {
                    throw new \UnexpectedValueException('A download source yields strings, got ' . get_debug_type($chunk));
                }
                if ($chunk === '') {
                    continue;
                }
                if (!$started) {
                    self::sendHeaders($response, $filename, $mimeType);
                    $started = true;
                }
                if (!$response->write($chunk)) {
                    return 200;
                }
            }

            if (!$started) {
                self::sendHeaders($response, $filename, $mimeType);
            }
            $response->end();

            return 200;
        } catch (\Throwable $e) {
            $this->logger->log('error', "Download {$filename} failed: " . Logger::describe($e), $page);
            if ($started) {
                $response->close();
            } else {
                $response->status(500);
                $response->end('Download failed');
            }
            if ($onError !== null) {
                $onError($e, $page);
            }

            return 500;
        }
    }

    private static function sendHeaders(Response $response, string $filename, string $mimeType): void {
        $fallback = (string) preg_replace('/[^\x20-\x7E]|["\\\\]/u', '_', $filename);
        $response->header('Content-Type', $mimeType);
        $response->header('Content-Disposition', 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
    }
}
