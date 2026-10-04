<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * The response of a page or an action that ran behind middleware: php-via sets its status, headers and cookies on
 * the client's response as usual, but the end waits until the middleware has returned, so that release() can add
 * the headers the middleware put on the response it got back from $handler->handle().
 *
 * @internal used by RequestHandler
 */
final class HeldResponse extends Response {
    /** Headers only php-via writes, whatever the middleware sets. */
    private const array OWN = [
        'content-type' => true,
        'content-length' => true,
        'content-encoding' => true,
        'transfer-encoding' => true,
        'connection' => true,
    ];

    /** Headers whose values the middleware's add to php-via's: two policies are both enforced. */
    private const array JOINED = [
        'vary' => true,
        'content-security-policy' => true,
        'content-security-policy-report-only' => true,
    ];

    /** @var array<string, string> the headers php-via set, by lower-cased name */
    private array $headers = [];

    /** @var array<string, true> the cookies php-via set, by name */
    private array $cookies = [];

    private ?string $body = null;
    private bool $ended = false;
    private bool $streaming = false;

    public function __construct(private Response $target) {
        $this->fd = $target->fd;
    }

    /**
     * Add the middleware's headers to the client's response and end it with the held body. php-via's own headers
     * and cookies win: see OWN and JOINED.
     *
     * @return list<string> the names of the headers and cookies of the middleware that were left out
     */
    public function release(ResponseInterface $fromMiddleware): array {
        if (!$this->ended || $this->streaming) {
            return [];
        }

        $refused = [];
        foreach ($fromMiddleware->getHeaders() as $name => $values) {
            $name = (string) $name;
            $lower = strtolower($name);
            if ($lower === 'set-cookie') {
                foreach ($values as $line) {
                    $cookie = PsrResponseEmitter::parseSetCookie($line);
                    if ($cookie === null) {
                        continue;
                    }
                    if (isset($this->cookies[$cookie[0]]) || $cookie[0] === SessionManager::SESSION_COOKIE_NAME || $cookie[0] === SessionManager::SESSION_COOKIE_NAME_SECURE) {
                        $refused[] = 'Set-Cookie ' . $cookie[0];

                        continue;
                    }
                    $this->target->rawcookie(...$cookie);
                }

                continue;
            }

            $value = implode(', ', $values);
            if (isset(self::OWN[$lower])) {
                $refused[] = $name;

                continue;
            }
            if (isset($this->headers[$lower])) {
                if (!isset(self::JOINED[$lower])) {
                    $refused[] = $name;

                    continue;
                }
                $value = $lower === 'vary' ? self::joinVary($this->headers[$lower], $value) : $this->headers[$lower] . ', ' . $value;
            }
            $this->target->header($name, $value);
        }

        $this->target->end($this->body);

        return $refused;
    }

    public function header(string $key, string $value, bool $format = true): bool {
        $lower = strtolower($key);
        if ($lower === 'set-cookie') {
            $cookie = PsrResponseEmitter::parseSetCookie($value);
            if ($cookie !== null) {
                $this->cookies[$cookie[0]] = true;
            }
        } else {
            $this->headers[$lower] = $value;
        }

        return $this->target->header($key, $value, $format);
    }

    public function setHeader(string $key, string $value, bool $format = true): bool {
        return $this->header($key, $value, $format);
    }

    public function cookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        $this->cookies[$key] = true;

        return $this->target->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function setCookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function rawcookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        $this->cookies[$key] = true;

        return $this->target->rawcookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function status(int $statusCode, string $reason = ''): bool {
        return $this->target->status($statusCode, $reason);
    }

    public function setStatusCode(int $statusCode, string $reason = ''): bool {
        return $this->status($statusCode, $reason);
    }

    public function isWritable(): bool {
        return !$this->ended && $this->target->isWritable();
    }

    /**
     * A response that streams sends its head with the first chunk, so the middleware's headers cannot join it.
     */
    public function write(string $data): bool {
        $this->streaming = true;

        return $this->target->write($data);
    }

    public function end(?string $data = null): bool {
        if ($this->streaming) {
            $this->ended = true;

            return $this->target->end($data);
        }
        if ($this->ended) {
            return false;
        }
        $this->ended = true;
        $this->body = $data;

        return true;
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool {
        $this->streaming = $this->ended = true;

        return $this->target->sendfile($fileName, $offset, $length);
    }

    public function close(): bool {
        $this->streaming = $this->ended = true;

        return $this->target->close();
    }

    public function redirect(string $url, int $status_code = 302): bool {
        $this->status($status_code);
        $this->header('Location', $url);

        return $this->end();
    }

    private static function joinVary(string $own, string $added): string {
        $tokens = [];
        foreach (explode(',', $own . ',' . $added) as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[strtolower($token)] ??= $token;
            }
        }

        return implode(', ', $tokens);
    }
}
