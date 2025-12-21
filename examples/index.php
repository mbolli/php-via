<?php
declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require_once __DIR__ . '/../vendor/autoload.php';

// Configuration: Set USE_SUBPATHS=true when running behind a reverse proxy (production)
// Set USE_SUBPATHS=false for local development with separate ports
$useSubpaths = getenv('USE_SUBPATHS') === '1';
$baseUrl = $useSubpaths ? '' : 'http://localhost';

$examples = [
    // Beginner
    ['title' => '🔢 Counter Basic', 'port' => 3002, 'subpath' => '/counter-basic', 'difficulty' => 'Beginner', 'file' => 'counter_basic.php', 'rendering' => 'twig', 'description' => 'Simplest counter example. Demonstrates minimal Via setup with inline Twig rendering and reactive signals.'],
    ['title' => '⚡ Counter', 'port' => 3001, 'subpath' => '/counter', 'difficulty' => 'Beginner', 'file' => 'counter.php', 'rendering' => 'html', 'description' => 'Basic counter with increment button. Uses signals with data binding and actions. Shows SSE with debug sidebar.'],
    ['title' => '👋 Greeter', 'port' => 3003, 'subpath' => '/greeter', 'difficulty' => 'Beginner', 'file' => 'greeter.php', 'rendering' => 'twig', 'description' => 'Interactive greeting buttons. Shows Twig templates, multiple actions, and signal updates with SSE.'],

    // Intermediate
    ['title' => '📊 All Scopes Demo', 'port' => 3012, 'subpath' => '/all-scopes', 'difficulty' => 'Intermediate', 'file' => 'all_scopes.php', 'rendering' => 'html', 'description' => 'Complete scope comparison. Shows global, route, and tab scopes working together in a single multi-page application.'],
    ['title' => '👁️ Client Monitor', 'port' => 3010, 'subpath' => '/monitor', 'difficulty' => 'Intermediate', 'file' => 'client_monitor.php', 'rendering' => 'html', 'description' => 'Live connected clients viewer with identicons and server stats. Shows route scope broadcasting, render statistics, and real-time client monitoring.'],
    ['title' => '🧩 Components', 'port' => 3005, 'subpath' => '/components', 'difficulty' => 'Intermediate', 'file' => 'components.php', 'rendering' => 'twig', 'description' => 'Three independent counter components. Demonstrates component abstraction with namespaced signals and Twig partials.'],
    ['title' => '🛣️  Path Parameters', 'port' => 3011, 'subpath' => '/path-params', 'difficulty' => 'Intermediate', 'file' => 'path_params.php', 'rendering' => 'html', 'description' => 'Dynamic routing showcase. Demonstrates path parameter extraction with multiple route patterns and inline styling.'],
    ['title' => '✓ Todo List', 'port' => 3004, 'subpath' => '/todo', 'difficulty' => 'Intermediate', 'file' => 'todo.php', 'rendering' => 'twig', 'description' => 'Multiplayer todo app—one shared list for all clients! Uses route scope, CRUD operations, and mixed tab/route scoped signals.'],

    // Advanced
    ['title' => '💬 Chat Room', 'port' => 3006, 'subpath' => '/chat', 'difficulty' => 'Advanced', 'file' => 'chat_room.php', 'rendering' => 'twig', 'description' => 'Multi-room chat system. Features custom room scopes, session-based usernames, typing indicators, and real-time broadcasting.'],
    ['title' => '🎮 Game of Life', 'port' => 3007, 'subpath' => '/gameoflife', 'difficulty' => 'Advanced', 'file' => 'game_of_life.php', 'rendering' => 'html', 'description' => 'Multiplayer Conway\'s Game of Life—everyone sees and controls the same board! Uses route scope, timer-based updates, and view caching.'],
    ['title' => '🔔 Global Notifications', 'port' => 3008, 'subpath' => '/notifications', 'difficulty' => 'Advanced', 'file' => 'global_notifications.php', 'rendering' => 'html', 'description' => 'System-wide notification banner. Demonstrates global scope with globalState() and broadcasting across all routes.'],
    ['title' => '📈 Stock Ticker', 'port' => 3009, 'subpath' => '/stocks', 'difficulty' => 'Advanced', 'file' => 'stock_ticker.php', 'rendering' => 'twig', 'description' => 'Multiplayer stock tracker—all clients see the same live prices! Uses route-scoped path parameters, ECharts visualization, and timer-based updates.'],
];

/**
 * Render the HTML for the examples list.
 *
 * @param array<int, array<string, mixed>> $examples
 */
function renderHtml(array $examples, bool $useSubpaths, string $baseUrl): string {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>php-via - Live Examples</title>
    <link rel="stylesheet" href="/via.css">
    <style>
        body {
            min-height: 100vh;
            padding: 2rem;
        }

        header {
            text-align: center;
            color: white;
            margin-bottom: 3rem;
        }

        header h1 {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }

        header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .examples-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        /* Mobile optimization for example cards */
        @media (max-width: 768px) {
            .examples-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            header h1 {
                font-size: 2rem;
            }

            header p {
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            body {
                padding: 1rem;
            }

            header {
                margin-bottom: 2rem;
            }

            header h1 {
                font-size: 1.75rem;
            }

            .examples-grid {
                gap: 0.75rem;
            }
        }

        /* Card-specific overrides (base card styles in via.css) */
        .info-box strong {
            color: var(--color-primary);
        }

        footer {
            text-align: center;
            color: white;
            margin-top: 3rem;
            opacity: 0.9;
        }

        footer a {
            color: var(--color-warning);
            text-decoration: none;
            font-weight: 600;
            transition: opacity 0.2s;
        }

        footer a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
    </style>
    <script type="module" src="../datastar.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>⚡ php-via Examples</h1>
            <p><?php echo $useSubpaths ? 'Live Interactive Examples' : 'Live Interactive Examples - Each Running on Its Own Port'; ?></p>
        </header>

        <div class="info-box">
            <h2>🚀 About These Examples</h2>
            <p>Each example runs as a separate Swoole server instance on its own port. This lets you explore different php-via features in isolation, compare approaches, and run multiple examples simultaneously. The cards below show live status indicators—click any card to open the example in a new tab.</p>
            <p><strong>⚡ Real-time updates</strong> using Server-Sent Events (SSE) for instant synchronization</p>
            <p><strong>🎯 Flexible scoping</strong> - Global, Route, Tab, and Custom scopes for different sharing needs</p>
            <p><strong>🔄 Reactive signals</strong> that automatically sync state between server and UI</p>
            <p><strong>🤝 Multiplayer by default</strong> - many examples share state across all connected clients</p>
        </div>

        <div class="examples-grid" id="examples-grid">
            <?php foreach ($examples as $example) {
                $url = $useSubpaths ? $example['subpath'] : "{$baseUrl}:{$example['port']}";
                $checkUrl = $useSubpaths ? $example['subpath'] : "http://localhost:{$example['port']}";
                ?>
                <div class="card example-card"
                   onclick="window.open('<?php echo $url; ?>', '_blank')"
                   data-signals="{'p<?php echo $example['port']; ?>': { 'online': false} }"
                   data-on-interval__duration.10s.leading="fetch('<?php echo $checkUrl; ?>', { method: 'HEAD', mode: 'no-cors' }).then(() => $p<?php echo $example['port']; ?>.online = true).catch(() => $p<?php echo $example['port']; ?>.online = false)">
                    <h3><?php echo htmlspecialchars($example['title']); ?></h3>
                    <div class="port">
                        <span class="status-indicator" data-class="{'online': $p<?php echo $example['port']; ?>.online, 'offline': !$p<?php echo $example['port']; ?>.online}"></span>
                        <?php echo $useSubpaths ? htmlspecialchars($example['subpath']) : "Port {$example['port']}"; ?>
                    </div>
                    <div class="description"><?php echo htmlspecialchars($example['description']); ?></div>
                    <div class="meta">
                        <span class="badge badge-<?php echo strtolower($example['difficulty']); ?>"><?php echo $example['difficulty']; ?></span>
                        <span class="rendering-badge <?php echo $example['rendering']; ?>"><?php echo strtoupper($example['rendering']); ?></span>
                        <a class="github-link"
                           href="https://github.com/mbolli/php-via/blob/master/examples/<?php echo $example['file']; ?>"
                           target="_blank"
                           onclick="event.stopPropagation();">
                            <svg viewBox="0 0 16 16" xmlns="http://www.w3.org/2000/svg"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z"/></svg>
                            Source
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>

        <footer>
            <p>Built with ❤️ using <a href="https://github.com/mbolli/php-via" target="_blank">php-via</a> and <a href="https://data-star.dev/" target="_blank">Datastar</a></p>
        </footer>
    </div>
</body>
</html>
    <?php
    return ob_get_clean();
}

$server = new Server('0.0.0.0', 3000);

$server->on('request', function (Request $request, Response $response) use ($examples, $useSubpaths, $baseUrl): void {
    // Serve via.css as a static file
    if ($request->server['request_uri'] === '/via.css') {
        $cssPath = __DIR__ . '/../templates/via.css';
        if (file_exists($cssPath)) {
            $response->header('Content-Type', 'text/css; charset=utf-8');
            $response->end(file_get_contents($cssPath));

            return;
        }
        $response->status(404);
        $response->end('Not Found');

        return;
    }

    // Serve datastar.js as a static file
    if ($request->server['request_uri'] === '/datastar.js') {
        $datastarPath = __DIR__ . '/../datastar.js';
        if (file_exists($datastarPath)) {
            $response->header('Content-Type', 'application/javascript');
            $response->end(file_get_contents($datastarPath));

            return;
        }
        $response->status(404);
        $response->end('Not Found');

        return;
    }

    // Serve the main HTML page
    $response->header('Content-Type', 'text/html; charset=utf-8');
    $response->end(renderHtml($examples, $useSubpaths, $baseUrl));
});

echo "🌟 Examples Landing Page Server started on http://0.0.0.0:3000\n";

$server->start();
