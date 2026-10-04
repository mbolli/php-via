<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Response;

/**
 * The response of a request another worker passed here (Forwarder): what php-via writes goes back to that worker,
 * which writes it to its client. A whole response travels as one message; a streamed one as its head and then
 * chunks, at most Forwarder::WINDOW of them unwritten at the receiver, so a slow client holds up the sender.
 *
 * @internal
 */
final class RelayResponse extends Response {
    private int $statusCode = 200;

    /** @var list<array{0: string, 1: string}> header calls in order */
    private array $headers = [];

    /** @var list<array{0: string, 1: list<mixed>}> cookie calls in order: method and arguments */
    private array $cookies = [];

    private bool $headSent = false;
    private bool $ended = false;
    private bool $abandoned = false;
    private int $unacked = 0;
    private ?Channel $acks = null;

    /**
     * @param array{int, int} $receiver  worker id and process id of the worker that passed the request
     * @param ?string         $contextId the tab of a forwarded action, whose cookies wait for its next response when the receiver gave up
     */
    public function __construct(private Forwarder $forwarder, private array $receiver, private int $id, private int $timeoutMs, private ?string $contextId) {
        $this->fd = 0;
    }

    /**
     * The receiver wrote a chunk.
     */
    public function acked(): void {
        $this->unacked = max(0, $this->unacked - 1);
        $this->wake();
    }

    /**
     * The receiver gave up on this request, or its client left: nothing more goes out.
     */
    public function abandon(): void {
        $this->abandoned = true;
        $this->wake();
    }

    public function isEnded(): bool {
        return $this->ended;
    }

    public function header(string $key, string $value, bool $format = true): bool {
        if ($this->headSent) {
            return false;
        }
        $this->headers[] = [$key, $value];

        return true;
    }

    public function setHeader(string $key, string $value, bool $format = true): bool {
        return $this->header($key, $value, $format);
    }

    public function cookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->recordCookie('cookie', [$key, $value ?? '', $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority]);
    }

    public function setCookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function rawcookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->recordCookie('rawcookie', [$key, $value ?? '', $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority]);
    }

    public function status(int $statusCode, string $reason = ''): bool {
        if ($this->headSent) {
            return false;
        }
        $this->statusCode = $statusCode;

        return true;
    }

    public function setStatusCode(int $statusCode, string $reason = ''): bool {
        return $this->status($statusCode, $reason);
    }

    public function isWritable(): bool {
        return !$this->ended && !$this->abandoned;
    }

    public function write(string $data): bool {
        if (!$this->isWritable()) {
            return false;
        }
        $this->sendHead();

        foreach ($data === '' ? [] : str_split($data, Forwarder::CHUNK_BYTES) as $chunk) {
            while ($this->unacked >= Forwarder::WINDOW) {
                if (!$this->awaitAck()) {
                    return false;
                }
            }
            ++$this->unacked;
            if (!$this->forwarder->send($this->receiver[0], ['chunk', $this->id, $chunk])) {
                $this->abandoned = true;

                return false;
            }
        }

        return true;
    }

    public function end(?string $data = null): bool {
        if ($this->ended) {
            return false;
        }
        if ($this->abandoned) {
            $this->ended = true;
            $this->requeue();

            return false;
        }

        if (!$this->headSent) {
            $this->ended = $this->headSent = true;

            return $this->forwarder->send($this->receiver[0], ['res', $this->id, $this->statusCode, $this->headers, $this->cookies, $data ?? '', $this->contextId]);
        }

        if ($data !== null && $data !== '' && !$this->write($data)) {
            $this->ended = true;

            return false;
        }
        $this->ended = true;

        return $this->forwarder->send($this->receiver[0], ['end', $this->id]);
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool {
        if (!$this->isWritable()) {
            return false;
        }
        if ($this->headSent) {
            $contents = file_get_contents($fileName, false, null, $offset, $length > 0 ? $length : null);

            return $this->end($contents === false ? '' : $contents);
        }

        $this->ended = $this->headSent = true;

        return $this->forwarder->send($this->receiver[0], ['file', $this->id, $this->statusCode, $this->headers, $this->cookies, $this->contextId, $fileName, $offset, $length]);
    }

    public function close(): bool {
        if ($this->ended) {
            return false;
        }
        $this->ended = true;

        return $this->forwarder->send($this->receiver[0], ['close', $this->id]);
    }

    public function redirect(string $url, int $status_code = 302): bool {
        $this->status($status_code);
        $this->header('Location', $url);

        return $this->end();
    }

    /**
     * @param list<mixed> $args
     */
    private function recordCookie(string $method, array $args): bool {
        if ($this->headSent) {
            return false;
        }
        $this->cookies[] = [$method, $args];

        return true;
    }

    private function sendHead(): void {
        if ($this->headSent) {
            return;
        }
        $this->headSent = true;
        $this->forwarder->send($this->receiver[0], ['head', $this->id, $this->statusCode, $this->headers, $this->cookies, $this->contextId]);
    }

    /**
     * Wait until the receiver has written a chunk; false once it gave up or stopped.
     */
    private function awaitAck(): bool {
        $this->acks ??= new Channel(1);
        $deadline = hrtime(true) + $this->timeoutMs * 1_000_000;
        while (!$this->abandoned && $this->unacked >= Forwarder::WINDOW) {
            if ($this->acks->pop(max(0.001, min(Forwarder::POLL_S, ($deadline - hrtime(true)) / 1e9))) === false
                && (hrtime(true) >= $deadline || !$this->forwarder->isLive($this->receiver))) {
                $this->abandoned = true;
            }
        }

        return !$this->abandoned;
    }

    private function wake(): void {
        if ($this->acks !== null && $this->acks->isEmpty()) {
            $this->acks->push(true, 0.001);
        }
    }

    /**
     * Hand the cookies of an action whose receiver gave up to the tab's next response.
     */
    private function requeue(): void {
        if ($this->contextId !== null && ($this->cookies !== [] || $this->headers !== [])) {
            $this->forwarder->requeueLocally($this->contextId, $this->cookies, $this->headers);
        }
    }
}
