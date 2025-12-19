<?php
declare(strict_types=1);

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

require_once __DIR__ . '/../vendor/autoload.php';

$examples = [
    ['title' => '⚡ Counter', 'port' => 3001, 'difficulty' => 'Beginner', 'file' => 'counter.php', 'rendering' => 'html', 'description' => 'Basic counter with increment button. Uses signals with data binding and actions. Shows SSE with debug sidebar.'],
    ['title' => '🔢 Counter Basic', 'port' => 3002, 'difficulty' => 'Beginner', 'file' => 'counter_basic.php', 'rendering' => 'twig', 'description' => 'Simplest counter example. Demonstrates minimal Via setup with inline Twig rendering and reactive signals.'],
    ['title' => '👋 Greeter', 'port' => 3003, 'difficulty' => 'Beginner', 'file' => 'greeter.php', 'rendering' => 'twig', 'description' => 'Interactive greeting buttons. Shows Twig templates, multiple actions, and signal updates with SSE.'],
    ['title' => '✓ Todo List', 'port' => 3004, 'difficulty' => 'Intermediate', 'file' => 'todo.php', 'rendering' => 'twig', 'description' => 'Multiplayer todo app—one shared list for all clients! Uses route scope, CRUD operations, and mixed tab/route scoped signals.'],
    ['title' => '🧩 Components', 'port' => 3005, 'difficulty' => 'Intermediate', 'file' => 'components.php', 'rendering' => 'twig', 'description' => 'Three independent counter components. Demonstrates component abstraction with namespaced signals and Twig partials.'],
    ['title' => '💬 Chat Room', 'port' => 3006, 'difficulty' => 'Advanced', 'file' => 'chat_room.php', 'rendering' => 'twig', 'description' => 'Multi-room chat system. Features custom room scopes, session-based usernames, typing indicators, and real-time broadcasting.'],
    ['title' => '🎮 Game of Life', 'port' => 3007, 'difficulty' => 'Advanced', 'file' => 'game_of_life.php', 'rendering' => 'html', 'description' => 'Multiplayer Conway\'s Game of Life—everyone sees and controls the same board! Uses route scope, timer-based updates, and view caching.'],
    ['title' => '🔔 Global Notifications', 'port' => 3008, 'difficulty' => 'Advanced', 'file' => 'global_notifications.php', 'rendering' => 'html', 'description' => 'System-wide notification banner. Demonstrates global scope with globalState() and broadcasting across all routes.'],
    ['title' => '📈 Stock Ticker', 'port' => 3009, 'difficulty' => 'Advanced', 'file' => 'stock_ticker.php', 'rendering' => 'twig', 'description' => 'Multiplayer stock tracker—all clients see the same live prices! Uses route-scoped path parameters, ECharts visualization, and timer-based updates.'],
    ['title' => '👤 Profile Demo', 'port' => 3010, 'difficulty' => 'Intermediate', 'file' => 'profile_demo.php', 'rendering' => 'html', 'description' => 'Connected clients viewer with identicons. Shows route scope broadcasting, render statistics, and inline HTML rendering.'],
    ['title' => '🛣️  Path Parameters', 'port' => 3011, 'difficulty' => 'Intermediate', 'file' => 'path_params.php', 'rendering' => 'html', 'description' => 'Dynamic routing showcase. Demonstrates path parameter extraction with multiple route patterns and inline styling.'],
    ['title' => '📊 All Scopes Demo', 'port' => 3012, 'difficulty' => 'Intermediate', 'file' => 'all_scopes.php', 'rendering' => 'html', 'description' => 'Complete scope comparison. Shows global, route, and tab scopes working together in a single multi-page application.'],
];

/**
 * Render the HTML for the examples list.
 *
 * @param array<int, array<string, mixed>> $examples
 */
function renderHtml(array $examples): string {
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>php-via - Live Examples</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            color: white;
            margin-bottom: 3rem;
        }

        header h1 {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        header p {
            font-size: 1.2rem;
            opacity: 0.9;
        }

        .examples-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .example-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
            position: relative;
            cursor: pointer;
        }

        .rendering-flag {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .rendering-flag.twig {
            background: #e3f2fd;
            color: #1565c0;
        }

        .rendering-flag.html {
            background: #fff3e0;
            color: #e65100;
        }

        .example-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .example-card h3 {
            font-size: 1.4rem;
            margin-bottom: 0.5rem;
            color: #667eea;
        }

        .example-card .port {
            font-size: 0.9rem;
            color: #999;
            margin-bottom: 1rem;
        }

        .example-card .status-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .example-card .status-indicator.online {
            background: #4caf50;
            box-shadow: 0 0 8px rgba(76, 175, 80, 0.5);
        }

        .example-card .status-indicator.offline {
            background: #f44336;
        }

        .example-card .description {
            font-size: 0.9rem;
            color: #666;
            line-height: 1.5;
            margin-bottom: 1rem;
            flex-grow: 1;
        }

        .example-card .meta {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
            margin-top: auto;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-beginner {
            background: #d4edda;
            color: #155724;
        }

        .badge-intermediate {
            background: #fff3cd;
            color: #856404;
        }

        .badge-advanced {
            background: #f8d7da;
            color: #721c24;
        }

        .rendering-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .rendering-badge.twig {
            background: #e3f2fd;
            color: #1565c0;
        }

        .rendering-badge.html {
            background: #fff3e0;
            color: #e65100;
        }

        .github-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #24292e;
            color: white;
            text-decoration: none;
            transition: background 0.2s;
        }

        .github-link:hover {
            background: #40464e;
        }

        .github-link svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }

        .info-box {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .info-box h2 {
            color: #667eea;
            margin-bottom: 1rem;
        }

        .info-box p {
            color: #666;
            line-height: 1.6;
            margin-bottom: 0.5rem;
        }

        footer {
            text-align: center;
            color: white;
            margin-top: 3rem;
            opacity: 0.8;
        }

        footer a {
            color: white;
            text-decoration: underline;
        }
    </style>
    <script type="module" src="../datastar.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>⚡ php-via Examples</h1>
            <p>Live Interactive Examples - Each Running on Its Own Port</p>
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
            <?php foreach ($examples as $example) { ?>
                <div class="example-card"
                   onclick="window.open('http://localhost:<?php echo $example['port']; ?>', '_blank')"
                   data-signals="{'p<?php echo $example['port']; ?>': { 'online': false} }"
                   data-on-interval__duration.10s.leading="fetch('http://localhost:<?php echo $example['port']; ?>', { method: 'HEAD', mode: 'no-cors' }).then(() => $p<?php echo $example['port']; ?>.online = true).catch(() => $p<?php echo $example['port']; ?>.online = false)">
                    <h3><?php echo htmlspecialchars($example['title']); ?></h3>
                    <div class="port">
                        <span class="status-indicator" data-class="{'online': $p<?php echo $example['port']; ?>.online, 'offline': !$p<?php echo $example['port']; ?>.online}"></span>
                        Port <?php echo $example['port']; ?>
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

$server->on('request', function (Request $request, Response $response) use ($examples): void {
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
    $response->end(renderHtml($examples));
});

echo "🌟 Examples Landing Page Server started on http://0.0.0.0:3000\n";

$server->start();
