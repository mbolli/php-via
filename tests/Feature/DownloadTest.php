<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Http\DownloadHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Http\Response;
use Tests\Support\FakeRequest;

/*
 * Context::download(): a one-shot URL, bound to the tab's context and session, that sends a file or what a
 * callable returns or yields over plain HTTP.
 */

const DOWNLOAD_SESSION = '00112233445566778899aabbccddeeff';
const DOWNLOAD_OTHER_SESSION = 'ffeeddccbbaa99887766554433221100';

/** Captures what a download writes. */
final class DownloadResponse extends Response {
    /** @var array<string, string> */
    public array $headers = [];

    public int $statusCode = 200;

    /** @var list<string> */
    public array $writes = [];

    public ?string $ended = null;

    public ?string $sentFile = null;

    public bool $closed = false;

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        $this->headers[$key] = (string) $value;

        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        $this->statusCode = $statusCode;

        return true;
    }

    public function write(string $data): bool {
        $this->writes[] = $data;

        return true;
    }

    public function end(mixed $data = null): bool {
        $this->ended = (string) $data;

        return true;
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool {
        $this->sentFile = $fileName;

        return true;
    }

    public function close(mixed $fd = null, mixed $reset = null): bool {
        $this->closed = true;

        return true;
    }

    public function isWritable(): bool {
        return $this->ended === null && !$this->closed;
    }

    public function body(): string {
        return implode('', $this->writes) . ($this->ended ?? '');
    }
}

function downloadPage(Via $via, string $session = DOWNLOAD_SESSION): Context {
    $page = new Context('/export_/' . bin2hex(random_bytes(6)), '/export', $via, null, $session);
    $via->contexts[$page->getId()] = $page;
    $via->getApp()->registerContext($page);

    return $page;
}

function fetchDownload(Via $via, string $url, string $session = DOWNLOAD_SESSION, string $method = 'GET'): DownloadResponse {
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $response = new DownloadResponse();
    $path = substr($url, strlen($via->getSettings()->basePath) - 1);
    $handler->handleRequest(new FakeRequest($method, $path, cookies: [SessionManager::SESSION_COOKIE_NAME => $session]), $response);

    return $response;
}

describe('Context::download()', function (): void {
    test('streams what a generator yields, chunk by chunk, as an attachment', function (): void {
        $via = createVia((new Config())->withBasePath('/app/'));
        $page = downloadPage($via);
        $ran = false;

        $url = $page->download(function () use (&$ran): Generator {
            $ran = true;

            yield "id,name\n";

            yield '';

            yield "1,Zoë\n";
        }, 'Flows "Zürich".csv', 'text/csv; charset=utf-8');

        expect($url)->toMatch('#^/app/_download/[0-9a-f]{32}$#')->and($ran)->toBeFalse('the source runs when the URL is fetched');

        $response = fetchDownload($via, $url);

        expect($response->statusCode)->toBe(200)
            ->and($response->writes)->toBe(["id,name\n", "1,Zoë\n"])
            ->and($response->ended)->toBe('')
            ->and($response->headers)->toBe([
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="Flows _Z_rich_.csv"; filename*=UTF-8\'\'Flows%20%22Z%C3%BCrich%22.csv',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ])
        ;
    });

    test('sends a file with sendfile(), and a string a callable returns', function (): void {
        $via = createVia();
        $page = downloadPage($via);
        $file = tempnam(sys_get_temp_dir(), 'via-download-');
        file_put_contents($file, 'file content');

        try {
            $fromFile = fetchDownload($via, $page->download($file, 'report.txt', 'text/plain'));
            $fromString = fetchDownload($via, $page->download(fn (): string => '{"ok":true}', 'data.json', 'application/json'));
        } finally {
            unlink($file);
        }

        expect($fromFile->sentFile)->toBe($file)
            ->and($fromFile->headers['Content-Type'] ?? null)->toBe('text/plain')
            ->and($fromString->ended)->toBe('{"ok":true}')
            ->and($fromString->writes)->toBe([])
        ;
    });

    test('works once', function (): void {
        $via = createVia();
        $url = downloadPage($via)->download(fn (): string => 'once', 'a.txt', 'text/plain');

        expect(fetchDownload($via, $url)->body())->toBe('once')
            ->and(fetchDownload($via, $url)->statusCode)->toBe(404)
        ;
    });

    test('answers another session 403 and keeps the download for its own', function (): void {
        $via = createVia();
        $url = downloadPage($via)->download(fn (): string => 'mine', 'a.txt', 'text/plain');

        expect(fetchDownload($via, $url, DOWNLOAD_OTHER_SESSION)->statusCode)->toBe(403)
            ->and(fetchDownload($via, $url)->body())->toBe('mine')
        ;
    });

    test('goes with its context, also when a component asked for it', function (): void {
        $via = createVia();
        $page = downloadPage($via);
        $page->component(static fn (Context $c) => $c->view(static fn (): string => '<p>c</p>'), 'exporter');
        $components = $page->getComponentRegistry();
        $component = end($components) ?: throw new LogicException('no component');
        $fromPage = $page->download(fn (): string => 'page', 'a.txt', 'text/plain');
        $fromComponent = $component->download(fn (): string => 'component', 'b.txt', 'text/plain');

        $page->cleanup();

        expect(fetchDownload($via, $fromPage)->statusCode)->toBe(404)
            ->and(fetchDownload($via, $fromComponent)->statusCode)->toBe(404)
        ;
    });

    test('keeps nothing for a context destroyed already, and its URL answers 404', function (): void {
        $via = createVia();
        $page = downloadPage($via);
        $page->cleanup();

        $url = $page->download(fn (): string => 'late', 'a.txt', 'text/plain');

        expect($url)->toMatch('#^/_download/[0-9a-f]{32}$#')
            ->and(fetchDownload($via, $url)->statusCode)->toBe(404)
            ->and((new ReflectionProperty(DownloadHandler::class, 'downloads'))->getValue($via->getApp()->downloads()))->toBe([])
        ;
    });

    test('answers other methods 405 without using the download up', function (string $method): void {
        $via = createVia();
        $url = downloadPage($via)->download(fn (): string => 'still here', 'a.txt', 'text/plain');

        $response = fetchDownload($via, $url, method: $method);

        expect($response->statusCode)->toBe(405)
            ->and($response->headers['Allow'] ?? null)->toBe('GET')
            ->and(fetchDownload($via, $url)->body())->toBe('still here')
        ;
    })->with(['HEAD', 'POST']);

    test('answers 500 without the attachment headers when the source throws before its first chunk', function (): void {
        $via = createVia();
        $logs = new LogBuffer();
        $via->getApp()->getLogger()->setBuffer($logs);
        $url = downloadPage($via)->download(function (): Generator {
            throw new RuntimeException('database gone');

            yield 'never';
        }, 'a.csv', 'text/csv');

        ob_start();

        try {
            $response = fetchDownload($via, $url);
        } finally {
            ob_end_clean();
        }

        expect($response->statusCode)->toBe(500)
            ->and($response->headers)->not->toHaveKey('Content-Disposition')
            ->and($response->ended)->toBe('Download failed')
            ->and(implode("\n", array_column($logs->since(0), 'message')))->toContain('Download a.csv failed')->toContain('database gone')
        ;
    });

    test('closes the connection when the source throws after its first chunk, so the download fails', function (): void {
        $via = createVia();
        $url = downloadPage($via)->download(function (): Generator {
            yield "row 1\n";

            throw new RuntimeException('cursor lost');
        }, 'a.csv', 'text/csv');

        ob_start();

        try {
            $response = fetchDownload($via, $url);
        } finally {
            ob_end_clean();
        }

        expect($response->writes)->toBe(["row 1\n"])
            ->and($response->closed)->toBeTrue()
            ->and($response->ended)->toBeNull()
        ;
    });

    test('throws for a path that is no file, a bad filename or a bad MIME type', function (?string $path, string $filename, string $mimeType, string $message): void {
        $page = downloadPage(createVia());

        expect(fn () => $page->download($path ?? fn (): string => '', $filename, $mimeType))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'content passed as a path' => ['a,b', 'a.csv', 'text/csv', 'For content in a string, pass fn () => $content'],
        'an empty filename' => [null, '', 'text/plain', 'needs a filename'],
        'a filename with a newline' => [null, "a\r\nSet-Cookie: x=1", 'text/plain', 'needs a filename'],
        'no subtype' => [null, 'a.txt', 'text', 'needs a MIME type'],
        'a header in the type' => [null, 'a.txt', "text/plain\r\nX-Evil: 1", 'needs a MIME type'],
    ]);
});

describe('Context::download() on a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('streams a generator and sends a file over HTTP, once, to the tab\'s session only', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/download_server.php') . ' 2>&1'
        );
        preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
        $r = [];
        foreach ($m as [, $key, $value]) {
            $r[$key] = $value;
        }

        expect($r['stream'] ?? null)->toBe('200 chunked text/csv rows=2000 intact=yes', $out)
            ->and($r['file'] ?? null)->toBe('200 length=12 file content')
            ->and($r['again'] ?? null)->toBe('404')
            ->and($r['stranger'] ?? null)->toBe('403')
        ;
    });
});
