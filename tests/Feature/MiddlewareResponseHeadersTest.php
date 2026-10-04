<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Mbolli\PhpVia\Http\HeldResponse;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Testing\TestRequest;
use Mbolli\PhpVia\Testing\TestResponse;
use Mbolli\PhpVia\Testing\TestTab;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// The headers a middleware puts on the response it gets back from $handler->handle(), on pages and actions.

/**
 * A middleware that adds $headers to the response of the request it passes on.
 *
 * @param array<string, list<string>|string> $headers
 */
function headersMiddleware(array $headers): MiddlewareInterface {
    return new class($headers) implements MiddlewareInterface {
        /** @param array<string, list<string>|string> $headers */
        public function __construct(private array $headers) {}

        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
            $response = $handler->handle($request);
            foreach ($this->headers as $name => $value) {
                $response = $response->withAddedHeader($name, $value);
            }

            return $response;
        }
    };
}

/**
 * @param array<string, list<string>|string> $headers
 */
function headersApp(array $headers, ?Config $config = null): TestApp {
    return new TestApp($config ?? (new Config())->withLogLevel('error'), static function (Via $via) use ($headers): void {
        $via->middleware(headersMiddleware($headers));
        $via->page('/p', function (Context $c): void {
            $count = $c->signal(0, 'count');
            $c->action(static fn () => $count->increment(), 'bump');
            $c->view(static fn (): string => '<p id="p">' . $count->int() . '</p>');
        });
    });
}

function headersActionUrl(TestTab $tab): string {
    return (string) parse_url($tab->context()->getAction('bump')?->url() ?? '', PHP_URL_PATH);
}

describe('a middleware header on a page or an action response', function (): void {
    test('reaches the browser with the page', function (): void {
        $app = headersApp([
            'Content-Security-Policy' => "script-src 'nonce-abc'",
            'Strict-Transport-Security' => 'max-age=63072000',
            'X-Frame-Options' => 'DENY',
            'Cache-Control' => 'no-store',
        ]);

        $page = $app->request('GET', '/p');

        expect($page->getStatusCode())->toBe(200)
            ->and($page->getHeaderLine('content-security-policy'))->toBe("script-src 'nonce-abc'")
            ->and($page->getHeaderLine('strict-transport-security'))->toBe('max-age=63072000')
            ->and($page->getHeaderLine('x-frame-options'))->toBe('DENY')
            ->and($page->getHeaderLine('cache-control'))->toBe('no-store')
            ->and((string) $page->getBody())->toContain('<p id="p">0</p>')
        ;
        $app->shutdown();
    });

    test('reaches the browser with an action', function (): void {
        $app = headersApp(['X-Frame-Options' => 'DENY']);
        $tab = $app->open('/p');

        $response = $tab->request('POST', headersActionUrl($tab), (string) json_encode(['via_ctx' => $tab->context()->getId()]), [
            'origin' => $app->origin(),
            'content-type' => 'application/json',
        ]);

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('x-frame-options'))->toBe('DENY')
            ->and($tab->signal('count'))->toBe(1)
        ;
        $app->shutdown();
    });

    test('leaves php-via its Content-Type and session cookie, and a cookie of another name passes', function (): void {
        $app = headersApp([
            'Content-Type' => 'text/plain',
            'Content-Length' => '3',
            'Set-Cookie' => [SessionManager::SESSION_COOKIE_NAME . '=' . str_repeat('0', 32) . '; Path=/', 'theme=dark%20blue; Path=/; Max-Age=60', 'gone=; Max-Age=0'],
        ]);
        $first = $app->request('GET', '/p');

        expect($first->getHeaderLine('content-type'))->toBe('text/html; charset=utf-8')
            ->and((string) $first->getBody())->toContain('<p id="p">0</p>')
        ;

        $response = new TestResponse();
        $app->send(new TestRequest('GET', '/p', [], []), $response);
        expect($response->cookies['theme'] ?? null)->toBe('dark blue')
            ->and($response->cookies)->toHaveKey('gone')
            ->and($response->cookies['gone'])->toBeNull()
            ->and($response->cookies[SessionManager::SESSION_COOKIE_NAME] ?? '')->not->toBe(str_repeat('0', 32))
        ;
        $app->shutdown();
    });

    test('joins the frame-ancestors policy of withEmbeddable(), so the browser enforces both', function (): void {
        $app = headersApp(
            ['Content-Security-Policy' => "script-src 'self'"],
            (new Config())->withEmbeddable('https://parent.example')->withLogLevel('error'),
        );

        expect($app->request('GET', '/p')->getHeaderLine('content-security-policy'))
            ->toBe("frame-ancestors https://parent.example, script-src 'self'")
        ;
        $app->shutdown();
    });

    test('in dev mode, a header php-via keeps its own of is warned about once', function (): void {
        $app = headersApp(['Content-Type' => 'text/plain'], (new Config())->withDevMode(true)->withLogLevel('warning'));
        $app->logs();

        $app->request('GET', '/p');
        $app->request('GET', '/p');

        expect(array_values(array_filter($app->logs(), static fn (string $line): bool => str_contains($line, 'Middleware set'))))
            ->toBe(['[WARN] Middleware set Content-Type on the response of /p, which php-via writes itself, so the middleware\'s is left out.'])
        ;
        $app->shutdown();
    });
});

describe('HeldResponse', function (): void {
    test('ends with the held body once released, joins Vary and keeps the cache headers php-via set', function (): void {
        $target = new TestResponse();
        $held = new HeldResponse($target);
        $held->header('Vary', 'Accept-Encoding');
        $held->header('Cache-Control', 'no-store');
        $held->end('body');

        expect($target->body)->toBe('')->and($held->isWritable())->toBeFalse();

        $refused = $held->release(new Psr7Response(200, [
            'Vary' => ['Cookie', 'accept-encoding'],
            'Cache-Control' => 'public, max-age=60',
            'Transfer-Encoding' => 'chunked',
            'Referrer-Policy' => 'no-referrer',
        ]));

        expect($target->body)->toBe('body')
            ->and($target->headers['vary'])->toBe('Accept-Encoding, Cookie')
            ->and($target->headers['cache-control'])->toBe('no-store')
            ->and($target->headers['referrer-policy'])->toBe('no-referrer')
            ->and($target->headers)->not->toHaveKey('transfer-encoding')
            ->and($refused)->toBe(['Cache-Control', 'Transfer-Encoding'])
        ;
    });
});

describe('PsrResponseEmitter', function (): void {
    test('sends every Set-Cookie line of a response, which OpenSwoole keeps one header value of', function (): void {
        $target = new class extends Response {
            /** @var list<string> */
            public array $calls = [];

            public function __construct() {}

            public function status(int $statusCode, string $reason = ''): bool {
                return true;
            }

            public function header(string $key, string $value, bool $format = true): bool {
                $this->calls[] = "header {$key}: {$value}";

                return true;
            }

            public function rawcookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
                $this->calls[] = "cookie {$key}={$value} path={$path} secure=" . (int) $secure . ' httponly=' . (int) $httpOnly . " samesite={$sameSite}";

                return true;
            }

            public function end(?string $data = null): bool {
                return true;
            }
        };

        (new PsrResponseEmitter())->emit(new Psr7Response(302, [
            'Location' => '/login',
            'Set-Cookie' => ['a=1; Path=/; Secure; HttpOnly; SameSite=Strict', 'b=2'],
            'Link' => ['</a.css>; rel=preload', '</b.js>; rel=preload'],
        ]), $target);

        expect($target->calls)->toBe([
            'header Location: /login',
            'cookie a=1 path=/ secure=1 httponly=1 samesite=Strict',
            'cookie b=2 path= secure=0 httponly=0 samesite=',
            'header Link: </a.css>; rel=preload, </b.js>; rel=preload',
        ]);
    });
});
