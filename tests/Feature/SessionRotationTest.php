<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Testing\TestRequest;
use Mbolli\PhpVia\Testing\TestResponse;
use Mbolli\PhpVia\Testing\TestTab;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * A login rotates the session cookie, so a cookie planted or read before it no longer reaches the
 * session. The session keeps its id, so its data, SESSION signals, scopes and tabs stay as they are;
 * the old cookie works for a grace period, for requests other tabs sent before the new one arrived.
 */

/** Rotates the session of a page request before or after the page renders, as ?rotate= asks. */
final class RotatingMiddleware implements MiddlewareInterface {
    /** @var list<string> */
    public array $errors = [];

    public function __construct(private Via $via) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $when = $request->getQueryParams()['rotate'] ?? '';
        if ($when === 'before') {
            $this->via->regenerateSession($request);
        }
        $response = $handler->handle($request);
        if ($when === 'after') {
            try {
                $this->via->regenerateSession($request);
            } catch (LogicException $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        return $response;
    }
}

final class RotationHandler implements RequestHandlerInterface {
    /** @param Closure(ServerRequestInterface): ResponseInterface $handle */
    public function __construct(private Closure $handle) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        return ($this->handle)($request);
    }
}

/**
 * An app with a login page, a route that reports the session and cookie it sees, a login route and a page behind
 * RotatingMiddleware, or with it as global middleware ($global). Its clock is $now, which a test moves past the grace period.
 */
function rotationApp(int &$now, ?RotatingMiddleware &$middleware = null, bool $global = false): TestApp {
    $app = new TestApp((new Config())->withLogLevel('error'), static function (Via $via) use (&$middleware, $global): void {
        $via->page('/p', static function (Context $c): void {
            $who = $c->signal('anon', 'who', Scope::SESSION);
            $n = $c->signal(0, 'n');
            $c->action(static function (Context $c) use ($who): void {
                $c->regenerateSession();
                $c->setSessionData('user', 'ada');
                $who->setValue('ada');
            }, 'login');
            $c->action(static function () use ($n): void {
                $n->setValue($n->int() + 1);
            }, 'bump');
            $c->view(static fn (): string => '<p id="v">' . $who->string() . ' ' . $n->int() . '</p>');
        });

        $via->route('GET', '/whoami', new RotationHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Psr7Response(200, [], (string) json_encode([
            'session' => $r->getAttribute('via.session'),
            'cookie' => $r->getCookieParams()[SessionManager::SESSION_COOKIE_NAME] ?? null,
        ]))));

        $via->route('POST', '/api/login', new RotationHandler(static function (ServerRequestInterface $r) use ($via): ResponseInterface {
            $via->regenerateSession($r);

            return new Psr7Response(204);
        }));

        // A login link: the page handler logs the visitor in.
        $via->page('/magic', static function (Context $c): void {
            $c->regenerateSession();
            $c->setSessionData('user', 'magic');
            $c->view(static fn (): string => '<p id="g">g</p>');
        });

        // Another tab's login finishes while this page renders.
        $via->page('/race', static function (Context $c) use ($via): void {
            $via->getSessionManager()->tokens()->rotate((string) $c->cookie(SessionManager::SESSION_COOKIE_NAME));
            $c->view(static fn (): string => '<p id="r">r</p>');
        });

        $middleware = new RotatingMiddleware($via);
        $page = $via->page('/m', static fn (Context $c) => $c->view(static fn (): string => '<p id="m">m</p>'));
        $global ? $via->middleware($middleware) : $page->middleware($middleware);
    });

    $via = $app->via();
    $via->getSessionManager()->useTokens(new SessionTokens(64, static fn (string $key): bool => $via->getApp()->hasSessionData($key), clock: static function () use (&$now): int {
        return $now;
    }));

    return $app;
}

/** @return array{session: string, cookie: null|string} what the tab's browser sends, and the session it names */
function rotationWhoami(TestTab $tab): array {
    /** @var array{session: string, cookie: null|string} */
    return json_decode((string) $tab->request('GET', '/whoami')->getBody(), true);
}

/** Send a request with $cookie as the session cookie. */
function rotationSend(TestApp $app, string $method, string $path, string $cookie, array $query = [], string $body = ''): TestResponse {
    $headers = $method === 'POST' ? ['origin' => $app->origin(), 'content-type' => 'application/json'] : [];
    $response = new TestResponse();
    $app->send(new TestRequest($method, $path, $query, [SessionManager::SESSION_COOKIE_NAME => $cookie], $headers, $body), $response);

    return $response;
}

function rotationAction(TestApp $app, TestTab $tab, string $action, string $cookie): TestResponse {
    return rotationSend($app, 'POST', '/_action/' . $action, $cookie, body: (string) json_encode(['via_ctx' => $tab->context()->getId()]));
}

/** Open an SSE stream of $tab with $cookie, and end it at once should it be accepted. */
function rotationConnect(TestApp $app, TestTab $tab, string $cookie): TestResponse {
    $context = $tab->context();
    $response = new TestResponse($app->nextFd());
    $request = new TestRequest('GET', '/_sse', ['datastar' => (string) json_encode(['via_ctx' => $context->getId()])], [SessionManager::SESSION_COOKIE_NAME => $cookie], ['accept' => 'text/event-stream']);
    $stream = $app->stream($request, $response);
    if (!$stream->isTerminated()) {
        $response->hangUp();
        $app->run(static fn () => $context->getPatchManager()->wakeConsumers());
    }

    return $response;
}

/**
 * Tokens with room for eight rows, a clock the second element moves, and the session keys that hold data.
 *
 * @return array{SessionTokens, Closure(int): void, ArrayObject<string, bool>}
 */
function rotationTokens(): array {
    $now = 1_000_000;

    /** @var ArrayObject<string, bool> $data */
    $data = new ArrayObject();
    $tokens = new SessionTokens(8, static fn (string $key): bool => isset($data[$key]), clock: static function () use (&$now): int {
        return $now;
    });

    return [$tokens, static function (int $seconds) use (&$now): void {
        $now += $seconds;
    }, $data];
}

describe('Context::regenerateSession()', function (): void {
    test('gives the session a new cookie with the action response, and keeps its id and data', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $tab->action('login');
        $after = rotationWhoami($tab);

        expect($after['cookie'])->not->toBe($before['cookie'])
            ->and(SessionManager::isValidSessionId((string) $after['cookie']))->toBeTrue()
            ->and($after['session'])->toBe($before['session'])
            ->and($tab->context()->getSessionId())->toBe($before['session'])
            ->and($app->via()->getSessionData($after['session'], 'user'))->toBe('ada')
        ;
    });

    test('rotates in a page handler, whose page sets the new cookie', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $tab->open('/magic');
        $after = rotationWhoami($tab);

        expect($after['cookie'])->not->toBe($before['cookie'])
            ->and($after['session'])->toBe($before['session'])
            ->and($app->via()->getSessionData($after['session'], 'user'))->toBe('magic')
        ;
    });

    test('keeps the SESSION signals and the session scope', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $first = $app->open('/p');
        $session = (string) $first->context()->getSessionId();
        $signalId = $first->context()->getSignal('who')?->id();

        $first->action('login');
        $next = $first->open('/p');

        expect($next->context()->getSessionId())->toBe($session)
            ->and($next->context()->getSignal('who')?->id())->toBe($signalId)
            ->and($next->context()->getScopes())->toContain(Scope::sessionScope($session))
            ->and($next->signal('who'))->toBe('ada')
        ;
    });

    test('keeps the other tabs of the session working, with the new cookie and with the old one in the grace period', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $other = $tab->open('/p');
        $old = (string) rotationWhoami($tab)['cookie'];

        $tab->action('login');
        $other->patches();
        $other->action('bump');
        $streamed = $other->patches();
        $other->disconnect()->connect();

        $now += SessionTokens::GRACE_SECONDS - 1;
        $inFlight = rotationAction($app, $other, 'bump', $old);

        expect($streamed)->toContain(['type' => 'signals', 'signals' => ['n' => 1]])
            ->and($inFlight->statusCode)->toBe(200)
            ->and($other->signal('n'))->toBe(2)
        ;
    });

    test('sets no cookie on a page loaded with the old cookie in the grace period, which would undo the rotation', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        ['session' => $session, 'cookie' => $old] = rotationWhoami($tab);
        $tab->action('login');

        $page = rotationSend($app, 'GET', '/p', (string) $old);
        $fresh = rotationSend($app, 'GET', '/p', (string) rotationWhoami($tab)['cookie']);

        expect($page->statusCode)->toBe(200)
            ->and($page->cookies)->toBe([])
            ->and(json_decode(rotationSend($app, 'GET', '/whoami', (string) $old)->body, true)['session'])->toBe($session)
            ->and(array_keys($fresh->cookies))->toBe([SessionManager::SESSION_COOKIE_NAME])
        ;
    });

    test('sets no cookie on a page whose cookie another request rotated while it rendered', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $cookie = (string) rotationWhoami($tab)['cookie'];

        $page = rotationSend($app, 'GET', '/race', $cookie);

        expect($page->statusCode)->toBe(200)
            ->and($page->cookies)->toBe([])
        ;
    });

    test('refuses the old cookie after the grace period: it starts a new session, and the tabs of the old one answer it 403', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        ['session' => $session, 'cookie' => $old] = rotationWhoami($tab);
        $tab->action('login');

        $now += SessionTokens::GRACE_SECONDS;
        $stolen = json_decode(rotationSend($app, 'GET', '/whoami', (string) $old)->body, true);
        $page = rotationSend($app, 'GET', '/p', (string) $old);
        $action = rotationAction($app, $tab, 'bump', (string) $old);
        $stream = rotationConnect($app, $tab, (string) $old);

        expect($stolen['session'])->not->toBe($session)
            ->and($app->via()->getSessionData($stolen['session'], 'user'))->toBeNull()
            ->and($page->cookies[SessionManager::SESSION_COOKIE_NAME] ?? null)->not->toBeNull()
            ->and($page->cookies[SessionManager::SESSION_COOKIE_NAME] ?? null)->not->toBe($old)
            ->and($action->statusCode)->toBe(403)
            ->and($stream->statusCode)->toBe(403)
            ->and(rotationWhoami($tab)['session'])->toBe($session)
        ;
        $tab->action('bump');
        expect($tab->signal('n'))->toBe(1);
    });

    test('called outside a request, rotates with the next action of the tab', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $tab->context()->regenerateSession();
        $unchanged = rotationWhoami($tab);
        $tab->action('bump');

        expect($unchanged)->toBe($before)
            ->and(rotationWhoami($tab)['cookie'])->not->toBe($before['cookie'])
            ->and(rotationWhoami($tab)['session'])->toBe($before['session'])
        ;
    });
});

describe('Via::regenerateSession()', function (): void {
    test('rotates in a route() handler, whose response sets the new cookie', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $response = $tab->request('POST', '/api/login', '', ['origin' => $app->origin()]);
        $after = rotationWhoami($tab);

        expect($response->getStatusCode())->toBe(204)
            ->and($after['cookie'])->not->toBe($before['cookie'])
            ->and($after['session'])->toBe($before['session'])
        ;
    });

    test('rotates in page middleware that calls it before the page renders, and throws after', function (): void {
        $now = 1_000_000;
        $middleware = null;
        $app = rotationApp($now, $middleware);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $tab->open('/m', ['rotate' => 'after']);
        $unchanged = rotationWhoami($tab);
        $tab->open('/m', ['rotate' => 'before']);
        $after = rotationWhoami($tab);

        expect($unchanged)->toBe($before)
            ->and($middleware?->errors)->toHaveCount(1)
            ->and($middleware?->errors[0] ?? '')->toContain('before $handler->handle()')
            ->and($after['cookie'])->not->toBe($before['cookie'])
            ->and($after['session'])->toBe($before['session'])
        ;
    });

    test('rotates in global middleware that calls it before an action runs, and throws after', function (): void {
        $now = 1_000_000;
        $middleware = null;
        $app = rotationApp($now, $middleware, global: true);
        $tab = $app->open('/p');
        $before = rotationWhoami($tab);

        $tab->action('bump', ['rotate' => 'after']);
        $unchanged = rotationWhoami($tab);
        $tab->action('bump', ['rotate' => 'before']);
        $after = rotationWhoami($tab);

        expect($unchanged)->toBe($before)
            ->and($middleware?->errors)->toHaveCount(1)
            ->and($after['cookie'])->not->toBe($before['cookie'])
            ->and($after['session'])->toBe($before['session'])
        ;
    });

    test('throws for a request php-via did not hand out', function (): void {
        $now = 1_000_000;
        $app = rotationApp($now);

        expect(fn () => $app->via()->regenerateSession(new ServerRequest('GET', '/')))
            ->toThrow(LogicException::class, 'the request php-via passed')
        ;
    });
});

describe('SessionTokens', function (): void {
    test('a cookie that never rotated names the session its hash keys, with no row', function (): void {
        [$tokens] = rotationTokens();
        $cookie = str_repeat('ab', 16);

        expect($tokens->lookup($cookie))->toBe([SessionTokens::key($cookie), SessionTokens::FRESH])
            ->and($tokens->count())->toBe(0)
        ;
    });

    test('a rotation makes the new cookie current and the old one retired after the grace period', function (): void {
        [$tokens, $advance] = rotationTokens();
        $first = str_repeat('ab', 16);
        $key = SessionTokens::key($first);

        $second = (string) $tokens->rotate($first);
        $inGrace = $tokens->lookup($first);
        $advance(SessionTokens::GRACE_SECONDS);

        expect($tokens->lookup($second))->toBe([$key, SessionTokens::CURRENT])
            ->and($inGrace)->toBe([$key, SessionTokens::GRACE])
            ->and($tokens->lookup($first))->toBe([$key, SessionTokens::RETIRED])
        ;
    });

    test('a cookie rotates once, and a cookie in its grace period does not rotate', function (): void {
        [$tokens] = rotationTokens();
        $first = str_repeat('ab', 16);

        $second = $tokens->rotate($first);
        $again = $tokens->rotate($first);
        $third = $tokens->rotate((string) $second);

        expect($second)->toBeString()
            ->and($again)->toBeNull()
            ->and($third)->toBeString()
            ->and($tokens->lookup((string) $third))->toBe([SessionTokens::key($first), SessionTokens::CURRENT])
            ->and($tokens->lookup((string) $second)[1])->toBe(SessionTokens::GRACE)
        ;
    });

    test('of two rotations of one cookie that interleave, as on two workers, only one issues a cookie', function (): void {
        $now = 1_000_000;
        $race = false;
        $inner = null;
        $tokens = null;
        $cookie = '';
        $tokens = new SessionTokens(8, static fn (): bool => false, clock: static function () use (&$now, &$race, &$inner, &$tokens, &$cookie): int {
            // The other worker's rotation runs between this one's lookup and its claim.
            if ($race) {
                $race = false;
                $inner = $tokens?->rotate($cookie);
            }

            return $now;
        });
        $cookie = (string) $tokens->rotate(str_repeat('ab', 16));

        $race = true;
        $outer = $tokens->rotate($cookie);

        expect($inner)->toBeString()
            ->and($outer)->toBeNull()
            ->and($tokens->lookup((string) $inner)[1])->toBe(SessionTokens::CURRENT)
        ;
    });

    test('a prune drops retired later cookies, and keeps the first cookie\'s row while its session holds data or saw a request within the hour', function (): void {
        [$tokens, $advance, $data] = rotationTokens();
        $kept = str_repeat('ab', 16);
        $idle = str_repeat('cd', 16);
        $data[SessionTokens::key($kept)] = true;
        $keptNext = (string) $tokens->rotate((string) $tokens->rotate($kept));
        $idleNext = (string) $tokens->rotate($idle);

        $advance(SessionTokens::GRACE_SECONDS);
        $first = $tokens->prune();
        $advance(3600);
        $second = $tokens->prune();

        expect($first)->toBe(1)
            ->and($second)->toBe(2)
            ->and($tokens->lookup($kept))->toBe([SessionTokens::key($kept), SessionTokens::RETIRED])
            ->and($tokens->lookup($keptNext))->toBe([SessionTokens::key($kept), SessionTokens::CURRENT])
            ->and($tokens->lookup($idle)[1])->toBe(SessionTokens::FRESH)
            ->and($tokens->lookup($idleNext))->toBe([SessionTokens::key($idleNext), SessionTokens::FRESH])
        ;
    });

    test('throws when every row belongs to a session that needs it', function (): void {
        [$tokens, , $data] = rotationTokens();
        for ($i = 0; $i < 4; ++$i) {
            $cookie = str_pad((string) $i, 32, 'a');
            $data[SessionTokens::key($cookie)] = true;
            $tokens->rotate($cookie);
        }

        expect(fn () => $tokens->rotate(str_repeat('ef', 16)))->toThrow(OverflowException::class, 'withSessionTableSize()');
    });
});

describe('with two workers', function (): void {
    test('a rotation on one worker reaches the other: the new cookie works there, and the old one only for the grace period', function (): void {
        $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/session_rotation_workers.php') . ' 2>&1');
        preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m);
        $r = array_combine($m[1], $m[2]);

        expect($r)->toMatchArray([
            'page' => '200',
            'stream_open' => '1',
            'workers' => '1',
            'login' => '200',
            'rotated' => '1',
            'b_new_same_session' => '1',
            'b_new_user' => 'ada',
            'b_old_in_grace_same_session' => '1',
            'b_bump_new' => '200',
            'stream_got_bump' => '1',
            'b_old_after_grace_same_session' => '0',
            'b_old_after_grace_user' => '-',
            'a_old_after_grace_same_session' => '0',
            'b_bump_old' => '403',
            'a_bump_old' => '403',
            'a_bump_new' => '200',
            'a_new_same_session' => '1',
            'stream_alive' => '1',
        ], $out);
    });
});
