<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;
use PhpVia\Website\Examples\AllScopesExample;
use PhpVia\Website\Examples\ChatRoomExample;
use PhpVia\Website\Examples\ClientMonitorExample;
use PhpVia\Website\Examples\ComponentsExample;
use PhpVia\Website\Examples\ContactFormExample;
use PhpVia\Website\Examples\CounterExample;
use PhpVia\Website\Examples\GameOfLifeExample;
use PhpVia\Website\Examples\GreeterExample;
use PhpVia\Website\Examples\LiveAuctionExample;
use PhpVia\Website\Examples\LiveSearchExample;
use PhpVia\Website\Examples\LoginExample;
use PhpVia\Website\Examples\MissionControlExample;
use PhpVia\Website\Examples\PathParamsExample;
use PhpVia\Website\Examples\ShoppingCartExample;
use PhpVia\Website\Examples\SpreadsheetExample;
use PhpVia\Website\Examples\StockTickerExample;
use PhpVia\Website\Examples\ThemeBuilderExample;
use PhpVia\Website\Examples\TodoExample;
use PhpVia\Website\Examples\TypeRaceExample;
use PhpVia\Website\Examples\WizardExample;
use PhpVia\Website\SyntaxHighlightExtension;
use PhpVia\Website\Twig\CodeRuntime;
use Psr\Log\AbstractLogger;
use Tuupola\Middleware\CorsMiddleware;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

// ─── Configuration ──────────────────────────────────────────────────────────

$isDev = getenv('APP_ENV') === 'dev';
$corsOrigin = getenv('CORS_ORIGIN') ?: '*';

$config = (new Config())
    ->withHost('0.0.0.0')
    ->withPort(3000)
    ->withDevMode($isDev)
    ->withTrustProxy(true)
    ->withTemplateDir(__DIR__ . '/templates')
    ->withStaticDir(__DIR__ . '/public')
    ->withLogLevel($isDev ? 'debug' : 'info')
;

if ($isDev) {
    // Dev: self-signed cert for direct HTTPS/HTTP2 (no Caddy needed)
    $certFile = __DIR__ . '/../certs/dev.crt';
    $keyFile = __DIR__ . '/../certs/dev.key';
    if (file_exists($certFile) && file_exists($keyFile)) {
        $config->withCertificate($certFile, $keyFile)->withBrotli();
    }
} else {
    // Prod: Caddy terminates TLS, php-via speaks h2c and handles Brotli
    $config->withH2c()->withBrotli();
}

$app = new Via($config);

// ─── Middleware ──────────────────────────────────────────────────────────────

$corsLogger = $config->getDevMode()
    ? new class extends AbstractLogger {
        public function log($level, string|Stringable $message, array $context = []): void {
            echo '[DEBUG] [CORS] ' . $message . "\n";
        }
    }
: null;

$app->middleware(new CorsMiddleware([
    'origin' => [$corsOrigin],
    'methods' => ['GET', 'POST'],
    'headers.allow' => ['Content-Type', 'Authorization'],
    'credentials' => true,
    'cache' => 3600,
    'origin.server' => !str_contains($corsOrigin, '*') ? $corsOrigin : null,
    'logger' => $corsLogger,
    'error' => function ($request, $response, $arguments) {
        $body = json_encode([
            'error' => 'CORS',
            'message' => $arguments['message'],
        ], JSON_UNESCAPED_SLASHES);

        $response = $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json')
        ;

        $response->getBody()->write($body);

        return $response;
    },
]));
$app->getTwig()->addExtension(new SyntaxHighlightExtension());
$app->getTwig()->addRuntimeLoader(new FactoryRuntimeLoader([
    CodeRuntime::class => fn () => new CodeRuntime(),
]));
$twig = $app->getTwig();

// Asset cache-busting version (based on CSS file mtime)
$cssPath = __DIR__ . '/public/css/site.css';
$twig->addGlobal('assetVersion', (string) (file_exists($cssPath) ? filemtime($cssPath) : time()));
$twig->addGlobal('siteUrl', 'https://via.zweiundeins.gmbh/');

// ─── Examples: register routes ───────────────────────────────────────────────

CounterExample::register($app);
GreeterExample::register($app);
TodoExample::register($app);
ComponentsExample::register($app);
PathParamsExample::register($app);
StockTickerExample::register($app);
ChatRoomExample::register($app);
ClientMonitorExample::register($app);
ClientMonitorExample::registerHooks($app);
AllScopesExample::register($app);
GameOfLifeExample::register($app);
SpreadsheetExample::register($app);
LiveSearchExample::register($app);
ShoppingCartExample::register($app);
ThemeBuilderExample::register($app);
WizardExample::register($app);
LoginExample::register($app);
ContactFormExample::register($app);
LiveAuctionExample::register($app);
TypeRaceExample::register($app);
MissionControlExample::register($app);

$app->onStart(function () use ($app): void {
    LiveAuctionExample::startTimer($app);
});

$app->onShutdown(function (): void {
    LiveAuctionExample::stopTimer();
});

// ─── Shared state ────────────────────────────────────────────────────────────

// (Scoped signals handle shared counter state — no globalState needed)

// ─── Presence: broadcast globally on connect/disconnect ──────────────────────
//
// Debounced: rapid connect/disconnect bursts (e.g. load tests) collapse into a
// single broadcast. Without this, N connections joining simultaneously triggers
// N broadcasts × N contexts = O(N²) renders that saturate the server.
// Timer fires 200ms after the last event.
/** @var null|int $presenceTimer */
$presenceTimer = null;

$broadcastPresence = function () use ($app, &$presenceTimer): void {
    if ($presenceTimer !== null) {
        Timer::clear($presenceTimer);
    }
    $presenceTimer = Timer::after(200, function () use ($app, &$presenceTimer): void {
        $presenceTimer = null;
        $app->broadcast(Scope::GLOBAL);
    });
};

$app->onClientConnect(function (Context $c) use ($broadcastPresence): void {
    $broadcastPresence();
});

$app->onClientDisconnect(function (Context $c) use ($broadcastPresence): void {
    $broadcastPresence();
});

// ─── Demo components ─────────────────────────────────────────────────────────

/**
 * Presence indicator: "🟢 N people on this page right now"
 * Route-scoped so it shows the client count for the current page.
 */
$presenceDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(Scope::GLOBAL);
    $c->view(function () use ($app, $twig): string {
        $count = count($app->getClients());

        return $twig->render('components/presence.html.twig', [
            'count' => $count,
            'person' => $count === 1 ? 'person' : 'people',
        ]);
    }, cacheUpdates: false);
};

/**
 * Shared multiplayer counter. ROUTE-scoped: all visitors share one counter.
 * The "aha" moment — click and everyone sees it.
 */
$sharedCounterDemo = function (Context $c) use ($twig): void {
    $c->scope(Scope::ROUTE);

    $counter = $c->signal(0, 'counter');
    $lastClick = $c->signal('', 'lastClick');
    $lastClickHue = $c->signal(0, 'lastClickHue');

    $increment = $c->action(function (Context $c) use ($counter, $lastClick, $lastClickHue): void {
        $counter->setValue($counter->int() + 1, broadcast: false);

        $visitorNum = substr($c->getId(), -4);
        $lastClick->setValue('Visitor #' . strtoupper($visitorNum), broadcast: false);
        $lastClickHue->setValue(hexdec($visitorNum) % 360, broadcast: false);
        $c->broadcast();
    }, 'increment');

    $c->view(fn () => $twig->render('components/shared-counter.html.twig', [
        'counter_id' => $counter->id(),
        'counter_val' => $counter->int(),
        'last_click_id' => $lastClick->id(),
        'last_click_val' => $lastClick->string(),
        'last_click_hue_id' => $lastClickHue->id(),
        'last_click_hue_val' => $lastClickHue->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Code + live result demo: syntax-highlighted PHP on the left, working counter on the right.
 */
$codeResultDemo = function (Context $c) use ($twig): void {
    $count = $c->signal(0, 'count');
    $increment = $c->action(function (Context $c) use ($count): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $c->view(fn () => $twig->render('components/code-result.html.twig', [
        'count_id' => $count->id(),
        'count_val' => $count->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Session counter for the home page demo section (demo box only, no code panel).
 * TAB-scoped so each visitor sees their own private counter.
 */
$homeSessionDemo = function (Context $c) use ($twig): void {
    $count = $c->signal(0, 'count');
    $increment = $c->action(function (Context $c) use ($count): void {
        $count->setValue($count->int() + 1);
        $c->sync();
    }, 'increment');

    $c->view(fn () => $twig->render('components/session-counter-demo.html.twig', [
        'count_id' => $count->id(),
        'count_val' => $count->int(),
        'increment_url' => $increment->url(),
    ]));
};

/**
 * Scope comparison: TAB-scoped vs ROUTE-scoped side by side.
 * The TAB counter is independent per visitor; the ROUTE counter is shared.
 */
$scopeComparisonDemo = function (Context $c) use ($app, $twig): void {
    // TAB-scoped: default, each visitor has their own
    $tabCount = $c->signal(0, 'tabCount');
    $incTab = $c->action(function (Context $c) use ($tabCount): void {
        $tabCount->setValue($tabCount->int() + 1);
        $c->sync();
    }, 'incTab');

    // ROUTE-scoped: shared across all visitors on this route
    $c->addScope(Scope::routeScope($c->getRoute()));
    $routeCount = $c->signal($app->globalState('scope_demo_count') ?? 0, 'routeCount', Scope::routeScope($c->getRoute()));
    $incRoute = $c->action(function (Context $c) use ($app, $routeCount): void {
        $newVal = ($app->globalState('scope_demo_count') ?? 0) + 1;
        $app->setGlobalState('scope_demo_count', $newVal);
        $routeCount->setValue($newVal);
        $app->broadcast(Scope::routeScope($c->getRoute()));
    }, 'incRoute');

    $c->view(fn () => $twig->render('components/scope-comparison.html.twig', [
        'tab_count_id' => $tabCount->id(),
        'tab_count_val' => $tabCount->int(),
        'route_count_id' => $routeCount->id(),
        'route_count_val' => $routeCount->int(),
        'inc_tab_url' => $incTab->url(),
        'inc_route_url' => $incRoute->url(),
    ]));
};

/**
 * Live poll: vote on "Favorite scope?" — bars shift in real-time for everyone.
 */
$livePollDemo = function (Context $c) use ($app, $twig): void {
    $c->scope(Scope::routeScope('/'));

    // Initialize vote counts in global state
    if ($app->globalState('poll_initialized') === null) {
        $app->setGlobalState('poll_tab', 0);
        $app->setGlobalState('poll_route', 0);
        $app->setGlobalState('poll_session', 0);
        $app->setGlobalState('poll_global', 0);
        $app->setGlobalState('poll_initialized', true);
    }

    $vote = $c->action(function (Context $c) use ($app): void {
        $raw = $c->input('option');
        if (!in_array($raw, ['tab', 'route', 'session', 'global'], true)) {
            return;
        }
        $key = 'poll_' . $raw;
        $app->setGlobalState($key, ($app->globalState($key) ?? 0) + 1);
        $app->broadcast(Scope::routeScope('/'));
    }, 'vote');

    $c->view(function () use ($app, $vote, $twig) {
        $counts = [
            'tab' => (int) ($app->globalState('poll_tab') ?? 0),
            'route' => (int) ($app->globalState('poll_route') ?? 0),
            'session' => (int) ($app->globalState('poll_session') ?? 0),
            'global' => (int) ($app->globalState('poll_global') ?? 0),
        ];
        $total = max(1, array_sum($counts));
        $defs = [
            'tab' => ['TAB', 'var(--blue-6)'],
            'route' => ['ROUTE', 'var(--violet-6)'],
            'session' => ['SESSION', 'var(--green-6)'],
            'global' => ['GLOBAL', 'var(--orange-5)'],
        ];
        $options = [];
        foreach ($defs as $key => [$label, $color]) {
            $options[] = [
                'key' => $key,
                'label' => $label,
                'color' => $color,
                'count' => (int) ($counts[$key] ?? 0),
                'pct' => round(((int) ($counts[$key] ?? 0) / $total) * 100),
                'url' => $vote->url() . '?option=' . $key,
            ];
        }

        return $twig->render('components/live-poll.html.twig', [
            'options' => $options,
        ]);
    }, cacheUpdates: false);
};

// ─── Routes ───────────────────────────────────────────────────────────────────

// Home page
$app->page('/', function (Context $c) use ($presenceDemo, $sharedCounterDemo, $homeSessionDemo, $livePollDemo): void {
    $c->scope(Scope::routeScope('/'));

    $presence = $c->component($presenceDemo, 'presence');
    $sharedCounter = $c->component($sharedCounterDemo, 'shared-counter');
    $sessionCounter = $c->component($homeSessionDemo, 'session-counter');
    $poll = $c->component($livePollDemo, 'poll');

    // On broadcast updates, skip re-rendering the full page — component sub-contexts
    // handle their own patches via their own target divs. Re-rendering here with stale
    // embedded component HTML would overwrite those fresh patches.
    $c->view(function (bool $isUpdate) use ($c, $presence, $sharedCounter, $sessionCounter, $poll): string {
        if ($isUpdate) {
            return '';
        }

        return $c->render('pages/home.html.twig', [
            'presence' => $presence(),
            'sharedCounter' => $sharedCounter(),
            'sessionCounter' => $sessionCounter(),
            'poll' => $poll(),
        ]);
    });
});

// ─── Docs routes ─────────────────────────────────────────────────────────────

$app->group('/docs', function (Via $app) use ($codeResultDemo, $scopeComparisonDemo): void {
    // Landing
    $app->page('/', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs'));
        $c->view('docs/index.html.twig');
    });

    // Getting started (tutorial with embedded demo)
    $app->page('/getting-started', function (Context $c) use ($codeResultDemo): void {
        $c->scope(Scope::routeScope('/docs/getting-started'));

        $demo = $c->component($codeResultDemo, 'gs-demo');

        $c->view('docs/getting-started.html.twig', [
            'demo' => $demo(),
        ]);
    });

    // Signals concept page
    $app->page('/signals', function (Context $c) use ($scopeComparisonDemo): void {
        $c->scope(Scope::routeScope('/docs/signals'));

        $demo = $c->component($scopeComparisonDemo, 'scope-demo');

        $c->view('docs/signals.html.twig', [
            'demo' => $demo(),
        ]);
    });

    // Scopes concept page
    $app->page('/scopes', function (Context $c) use ($scopeComparisonDemo): void {
        $c->scope(Scope::routeScope('/docs/scopes'));

        $demo = $c->component($scopeComparisonDemo, 'scope-demo');

        $c->view('docs/scopes.html.twig', [
            'demo' => $demo(),
        ]);
    });

    $app->page('/actions', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/actions'));
        $c->view('docs/actions.html.twig');
    });

    $app->page('/views', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/views'));
        $c->view('docs/views.html.twig');
    });

    $app->page('/components', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/components'));
        $c->view('docs/components.html.twig');
    });

    $app->page('/broadcasting', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/broadcasting'));
        $c->view('docs/broadcasting.html.twig');
    });

    $app->page('/broker', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/broker'));
        $c->view('docs/broker.html.twig');
    });

    $app->page('/lifecycle', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/lifecycle'));
        $c->view('docs/lifecycle.html.twig');
    });

    $app->page('/middleware', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/middleware'));
        $c->view('docs/middleware.html.twig');
    });

    $app->page('/twig', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/twig'));
        $c->view('docs/twig.html.twig');
    });

    $app->page('/deployment', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/deployment'));
        $c->view('docs/deployment.html.twig');
    });

    $app->page('/api', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/api'));
        $c->view('docs/api.html.twig');
    });

    $app->page('/design', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/design'));
        $c->view('docs/design.html.twig');
    });

    $app->page('/comparisons', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/comparisons'));
        $c->view('docs/comparisons.html.twig');
    });

    $app->page('/faq', function (Context $c): void {
        $c->scope(Scope::routeScope('/docs/faq'));
        $c->view('docs/faq.html.twig');
    });
});

// Examples gallery
$app->page('/examples', function (Context $c): void {
    $c->scope(Scope::routeScope('/examples'));
    $c->view('pages/examples-index.html.twig');
});

// ─── Start ───────────────────────────────────────────────────────────────────

$startScheme = $config->isHttps() ? 'https' : 'http';
echo "⚡ php-via website running on {$startScheme}://0.0.0.0:3000\n";
$app->start();
