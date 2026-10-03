<?php

declare(strict_types=1);

/*
 * Fixture for SessionRotationTest: a rotation on one worker, seen by the other.
 *
 * Two workers and a grace period of 2 s. A tab loads its page on worker A and streams from worker B.
 * Its login action on A rotates the session cookie. B then takes the new cookie and, until the grace
 * period ends, the old one; after it B and A refuse the old cookie, and the stream B opened before the
 * rotation still receives.
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

const ROTATION_GRACE = 2;

$port = FixturePort::pick(5250, 150);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum(2));
$app->getSessionManager()->useTokens(new SessionTokens(64, static fn (string $key): bool => $app->getApp()->hasSessionData($key), ROTATION_GRACE));

$app->page('/p', static function (Context $c): void {
    $n = $c->signal(0, 'n');
    $login = $c->action(static function (Context $c): void {
        $c->regenerateSession();
        $c->setSessionData('user', 'ada');
    }, 'login');
    $bump = $c->action(static function (Context $c) use ($n): void {
        $n->setValue($n->int() + 1);
        $c->sync();
    }, 'bump');
    $c->view(static fn (): string => '<p id="v">CTX:' . $c->getId() . ':LOGIN:' . $login->url() . ':BUMP:' . $bump->url() . ':N:' . $n->int() . ':END</p>');
});

$app->route('GET', '/whoami', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $session = (string) $request->getAttribute('via.session');

        return new Psr7Response(200, [], 'SESSION:' . $session . ':USER:' . (string) $this->app->getSessionData($session, 'user', '-') . ':PID:' . getmypid() . ':END');
    }
});

/**
 * One request on a keep-alive socket.
 *
 * @return array{0: int, 1: string, 2: string} status, the session cookie it sets ('' for none) and body
 */
function rotationRequest(mixed $sock, string $head, string $body = ''): array {
    fwrite($sock, $head . "\r\n" . $body);
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n") && !feof($sock)) {
        $raw .= (string) fread($sock, 1);
    }
    preg_match('/^HTTP\/1\.1 (\d+)/', $raw, $status);
    preg_match('/set-cookie: via_session_id=([0-9a-f]+)/i', $raw, $cookie);
    $content = '';
    if (preg_match('/content-length:\s*(\d+)/i', $raw, $m)) {
        while (strlen($content) < (int) $m[1] && !feof($sock)) {
            $content .= (string) fread($sock, (int) $m[1] - strlen($content));
        }
    } elseif (stripos($raw, 'chunked') !== false) {
        while (($size = hexdec(trim((string) fgets($sock)))) > 0) {
            $chunk = '';
            while (strlen($chunk) < $size && !feof($sock)) {
                $chunk .= (string) fread($sock, (int) $size - strlen($chunk));
            }
            $content .= $chunk;
            fgets($sock);
        }
        fgets($sock);
    }

    return [(int) ($status[1] ?? 0), $cookie[1] ?? '', $content];
}

/** @return array{0: string, 1: string} the session /whoami names for $cookie, and its user */
function rotationWhoami(mixed $sock, string $cookie): array {
    [, , $body] = rotationRequest($sock, "GET /whoami HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: via_session_id={$cookie}\r\n");
    preg_match('/SESSION:(\w*):USER:(.*?):PID:/', $body, $m);

    return [$m[1] ?? '?', $m[2] ?? '?'];
}

function rotationAction(mixed $sock, int $port, string $url, string $contextId, string $cookie): array {
    $body = (string) json_encode(['via_ctx' => $contextId]);

    return rotationRequest($sock, "POST {$url} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: via_session_id={$cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n", $body);
}

/** A keep-alive socket served by the worker with pid $pid, or by any other when $other is true, and the pid that served it. @return array{0: resource, 1: int} */
function rotationSocketOn(int $port, int $pid, bool $other): array {
    // Rejected sockets stay open until the end: a closed one frees its fd, and dispatch goes by fd.
    $rejected = [];

    try {
        for ($i = 0; $i < 20; ++$i) {
            $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
            [, , $body] = rotationRequest($sock, "GET /whoami HTTP/1.1\r\nHost: 127.0.0.1\r\n");
            preg_match('/PID:(\d+):END/', $body, $m);
            $served = (int) ($m[1] ?? 0);
            if ($pid === 0 || ($served === $pid) !== $other) {
                return [$sock, $served];
            }
            $rejected[] = $sock;
        }
    } finally {
        array_map(fclose(...), $rejected);
    }

    throw new RuntimeException('no connection reached the wanted worker');
}

/** What the stream sends within $seconds. */
function rotationDrain(mixed $sock, float $seconds): string {
    $out = '';
    $deadline = microtime(true) + $seconds;
    stream_set_timeout($sock, 0, 50_000);
    while (microtime(true) < $deadline && !feof($sock)) {
        $out .= (string) fread($sock, 65536);
    }

    return $out;
}

$app->setInterval(static function () use ($app, $port): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port): void {
        try {
            [$onA, $pidA] = rotationSocketOn($port, 0, false);
            [$status, $old, $page] = rotationRequest($onA, "GET /p HTTP/1.1\r\nHost: 127.0.0.1\r\n");
            preg_match('/CTX:(.+?):LOGIN:(.+?):BUMP:(.+?):N:/', html_entity_decode($page), $m);
            [, $contextId, $login, $bump] = $m + [null, '', '', ''];
            [$session] = rotationWhoami($onA, $old);
            echo 'page=', $status, "\n";

            [$stream, $pidB] = rotationSocketOn($port, $pidA, true);
            fwrite($stream, 'GET /_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $contextId]))
                . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: via_session_id={$old}\r\n\r\n");
            echo 'stream_open=', (int) str_starts_with(rotationDrain($stream, 0.4), 'HTTP/1.1 200'), "\n";
            echo 'workers=', (int) ($pidA !== $pidB && $pidB !== 0), "\n";

            [$loginStatus, $new] = rotationAction($onA, $port, $login, $contextId, $old);
            echo 'login=', $loginStatus, "\n";
            echo 'rotated=', (int) ($new !== '' && $new !== $old), "\n";

            [$onB] = rotationSocketOn($port, $pidA, true);
            [$bNew, $bUser] = rotationWhoami($onB, $new);
            [$bOld] = rotationWhoami($onB, $old);
            echo 'b_new_same_session=', (int) ($bNew === $session), "\n";
            echo 'b_new_user=', $bUser, "\n";
            echo 'b_old_in_grace_same_session=', (int) ($bOld === $session), "\n";

            [$bumpStatus] = rotationAction($onB, $port, $bump, $contextId, $new);
            echo 'b_bump_new=', $bumpStatus, "\n";
            echo 'stream_got_bump=', (int) str_contains(rotationDrain($stream, 0.5), 'N:1:END'), "\n";

            Coroutine::usleep((int) ((ROTATION_GRACE + 0.5) * 1_000_000));

            [$bOldAfter, $bOldUser] = rotationWhoami($onB, $old);
            [$aOldAfter] = rotationWhoami($onA, $old);
            echo 'b_old_after_grace_same_session=', (int) ($bOldAfter === $session), "\n";
            echo 'b_old_after_grace_user=', $bOldUser, "\n";
            echo 'a_old_after_grace_same_session=', (int) ($aOldAfter === $session), "\n";
            echo 'b_bump_old=', rotationAction($onB, $port, $bump, $contextId, $old)[0], "\n";
            echo 'a_bump_old=', rotationAction($onA, $port, $bump, $contextId, $old)[0], "\n";
            echo 'a_bump_new=', rotationAction($onA, $port, $bump, $contextId, $new)[0], "\n";
            [$aNew] = rotationWhoami($onA, $new);
            echo 'a_new_same_session=', (int) ($aNew === $session), "\n";
            echo 'stream_alive=', (int) !feof($stream), "\n";
            fclose($stream);
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
