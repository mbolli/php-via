<?php

declare(strict_types=1);

// Disable Xdebug for long-running SSE connections
ini_set('xdebug.mode', 'off');

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * All Scopes Demo.
 *
 * This example demonstrates all three scopes in a single application:
 * - GLOBAL scope: System status banner (visible on all pages)
 * - ROUTE scope: Shared counter per page (shared by all users on same page)
 * - TAB scope: Personal message (unique to each user)
 *
 * Open this in multiple tabs and different pages to see how each scope behaves!
 */

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3012)
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

// Add global styles
$app->appendToHead(
    <<<'HTML'
<title>All Scopes Demo</title>
<style>
    .page-header {
        margin-bottom: 2rem;
    }
    .page-header h1 {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
    }
    .page-header p {
        color: var(--color-light);
        font-size: 1.1rem;
    }
    .scope-banner {
        color: white;
        padding: 1.5rem;
        margin-bottom: 1rem;
        border-radius: var(--border-radius);
        box-shadow: var(--shadow-md);
        border: var(--border-width) solid;
        transition: all 0.3s ease;
    }
    .scope-banner:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-2px);
    }
    .scope-banner-global {
        background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-secondary) 100%);
        border-color: var(--color-secondary);
    }
    .scope-banner-route {
        background: linear-gradient(135deg, var(--color-accent) 0%, var(--color-danger) 100%);
        border-color: var(--color-danger);
    }
    .scope-banner-tab {
        background: linear-gradient(135deg, var(--color-success) 0%, var(--color-primary) 100%);
        border-color: var(--color-primary);
    }
    .scope-banner-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 1rem;
    }
    .scope-banner-content {
        flex: 1;
        min-width: 200px;
    }
    .scope-banner-title {
        font-weight: bold;
        font-size: 1rem;
        margin-bottom: 0.5rem;
    }
    .scope-banner-value {
        margin-top: 0.5rem;
        font-size: 1.1rem;
    }
    .scope-banner-counter {
        font-size: 2rem;
        font-weight: bold;
    }
    .scope-banner-note {
        font-size: 0.85rem;
        margin-top: 0.5rem;
        opacity: 0.9;
    }
    .scope-banner button {
        background: white;
        border: none;
        font-weight: bold;
        white-space: nowrap;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }

    .scope-banner button:hover {
        transform: translateY(-2px) scale(1.05);
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
    }
    .scope-banner button:active {
        transform: translateY(0) scale(0.98);
    }
    .scope-banner-global button {
        color: var(--color-secondary);
    }
    .scope-banner-global button:hover {
        box-shadow: 0 8px 20px rgba(139, 92, 246, 0.4);
    }
    .scope-banner-route button {
        color: var(--color-danger);
    }
    .scope-banner-route button:hover {
        box-shadow: 0 8px 20px rgba(255, 71, 87, 0.4);
    }
    .scope-banner-tab button {
        color: var(--color-success);
    }
    .scope-banner-tab button:hover {
        box-shadow: 0 8px 20px rgba(80, 200, 120, 0.4);
    }
    .scope-banner input[type="text"] {
        width: 100%;
        margin-top: 0.5rem;
        border: 2px solid white;
    }
    nav {
        margin-bottom: 2rem;
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    nav a {
        padding: 0.5rem 1rem;
        background: var(--color-success);
        color: white;
        text-decoration: none;
        border-radius: var(--border-radius);
        font-weight: bold;
        transition: all 0.2s ease;
    }
    nav a:hover {
        background: var(--color-primary);
        text-decoration: none;
    }
    nav a.active {
        background: var(--color-primary);
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 1rem;
    }
    th, td {
        padding: 0.75rem;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    th {
        background: var(--color-light);
        font-weight: bold;
        color: var(--color-dark);
    }
</style>
HTML
);

// Initialize global state
$app->setGlobalState('systemStatus', 'All systems operational');
$app->setGlobalState('totalVisitors', 0);

// Page state for route scope
class PageState {
    /** @var array<string, int> */
    public static array $counters = [
        '/' => 0,
        '/page-a' => 0,
        '/page-b' => 0,
    ];
}

// =============================================================================
// GLOBAL SCOPE COMPONENT - System Status Banner
// =============================================================================
$globalStatusBanner = function (Context $c) use ($app): void {
    // Set GLOBAL scope - shared across ALL pages and users
    $c->scope(Scope::GLOBAL);

    $updateStatus = $c->action(function (Context $ctx) use ($app): void {
        $statuses = ['All systems operational', 'Maintenance mode', 'High load detected', 'Everything is awesome!'];
        $newStatus = $statuses[array_rand($statuses)];
        $app->setGlobalState('systemStatus', $newStatus);

        $visitors = $app->globalState('totalVisitors', 0);
        $app->setGlobalState('totalVisitors', $visitors + 1);

        $app->log('info', "Global status updated: {$newStatus}");
        $app->broadcast(Scope::GLOBAL); // Updates EVERY page, EVERY user
    }, 'updateStatus');

    // GLOBAL scope: No signals, no route actions, ONLY global actions
    // This will be cached once for the ENTIRE app
    $c->view(function () use ($app, $updateStatus): string {
        $status = $app->globalState('systemStatus', 'Unknown');
        $visitors = $app->globalState('totalVisitors', 0);

        return <<<HTML
        <div class="scope-banner scope-banner-global">
            <div class="scope-banner-header">
                <div class="scope-banner-content">
                    <div class="scope-banner-title">🌍 GLOBAL SCOPE - System Status</div>
                    <div class="scope-banner-value">
                        Status: <strong>{$status}</strong> | Total Visitors: <strong>{$visitors}</strong>
                    </div>
                </div>
                <button data-on:click="@get('{$updateStatus->url()}')">
                    🔄 Update Global Status
                </button>
            </div>
            <div class="scope-banner-note">
                💡 This banner uses <strong>GLOBAL scope</strong> - rendered ONCE for entire app, shared across ALL pages and users!
            </div>
        </div>
        HTML;
    });
};

// =============================================================================
// ROUTE SCOPE COMPONENT - Shared Page Counter
// =============================================================================
$routeScopeCounter = function (Context $c) use ($app): void {
    // Component inherits parent page's route, so $c->getRoute() returns the correct route
    $route = $c->getRoute();

    // Set ROUTE scope - shared across all users on THIS route only
    $c->scope(Scope::ROUTE);

    $increment = $c->action(function (Context $ctx) use ($app, $route): void {
        ++PageState::$counters[$route];
        $app->log('info', "Counter for {$route} incremented to: " . PageState::$counters[$route]);
        $app->broadcast(Scope::ROUTE); // Updates all users on THIS route only
    }, 'increment_' . str_replace('/', '_', $route));

    $reset = $c->action(function (Context $ctx) use ($app, $route): void {
        PageState::$counters[$route] = 0;
        $app->log('info', "Counter for {$route} reset to 0");
        $app->broadcast(Scope::ROUTE);
    }, 'reset_' . str_replace('/', '_', $route));

    // ROUTE scope: No signals, ONLY route actions
    // This will be cached per route - all users on same page see same count
    $c->view(function () use ($route, $increment, $reset): string {
        $count = PageState::$counters[$route] ?? 0;

        return <<<HTML
        <div class="scope-banner scope-banner-route">
            <div class="scope-banner-header">
                <div class="scope-banner-content">
                    <div class="scope-banner-title">🎯 ROUTE SCOPE - Shared Page Counter</div>
                    <div class="scope-banner-counter">{$count}</div>
                </div>
                <div style="display: flex; gap: 0.5rem;">
                    <button data-on:click="@get('{$increment->url()}')">
                        ➕ Increment
                    </button>
                    <button data-on:click="@get('{$reset->url()}')">
                        🔄 Reset
                    </button>
                </div>
            </div>
            <div class="scope-banner-note">
                💡 This counter uses <strong>ROUTE scope</strong> - shared by all users on THIS page only. Try opening multiple tabs of the same page!
            </div>
        </div>
        HTML;
    });
};

// =============================================================================
// TAB SCOPE COMPONENT - Personal Message
// =============================================================================
$tabScopeMessage = function (Context $c): void {
    // Using a signal = TAB scope
    // Each user gets their own personal message
    $message = $c->signal('Hello from your personal tab!', 'personalMessage');

    $updateMessage = $c->action(function () use ($message, $c): void {
        $messages = [
            'You are awesome! 🌟',
            'Having a great day? 😊',
            'Keep coding! 💻',
            'This is YOUR personal message! 🎯',
            'Tab scope is cool! 🚀',
        ];
        $message->setValue($messages[array_rand($messages)]);
        $c->syncSignals();
    }, 'updateMessage');

    // TAB scope: Uses signals
    // This renders fresh for EACH user - no caching
    $c->view(fn (): string => <<<HTML
        <div class="scope-banner scope-banner-tab">
            <div class="scope-banner-header">
                <div class="scope-banner-content">
                    <div class="scope-banner-title">👤 TAB SCOPE - Your Personal Message</div>
                    <input type="text" {$message->bind()}>
                    <div class="scope-banner-value">
                        Your message: <span data-text="\${$message->id()}"></span>
                    </div>
                </div>
                <button data-on:click="@post('{$updateMessage->url()}')">
                    🎲 Random Message
                </button>
            </div>
            <div class="scope-banner-note">
                💡 This uses <strong>TAB scope</strong> - each tab/user has their own message. Try opening multiple tabs!
            </div>
        </div>
        HTML);
};

// =============================================================================
// Create the page template
// =============================================================================
$createPage = function (string $title, string $route, string $content) use ($app, $globalStatusBanner, $routeScopeCounter, $tabScopeMessage): void {
    $app->page($route, function (Context $c) use ($title, $route, $content, $globalStatusBanner, $routeScopeCounter, $tabScopeMessage): void {
        $globalBanner = $c->component($globalStatusBanner, 'global');
        $routeCounter = $c->component($routeScopeCounter, 'route');
        $tabMessage = $c->component($tabScopeMessage, 'private');

        $c->view(function (bool $isUpdate = false) use ($title, $route, $content, $globalBanner, $routeCounter, $tabMessage): string {
            // Only return the component blocks on updates (SSE)
            if ($isUpdate) {
                return "{$globalBanner()}{$routeCounter()}{$tabMessage()}";
            }

            // Initial render - full page
            $otherPages = [
                '/' => '🏠 Home',
                '/page-a' => '📄 Page A',
                '/page-b' => '📄 Page B',
            ];

            $nav = '';
            foreach ($otherPages as $path => $label) {
                $activeClass = $path === $route ? ' class="active"' : '';
                $nav .= "<a href=\"{$path}\"{$activeClass}>{$label}</a>";
            }

            return <<<HTML
            <div class="container">
                <div class="page-header">
                    <h1>🌐 All Scopes Demo</h1>
                    <p>Explore Global, Route, and Tab scopes in action</p>
                </div>

                <nav>{$nav}</nav>

                {$globalBanner()}
                {$routeCounter()}
                {$tabMessage()}

                <div class="info-box">
                    <h1>{$title}</h1>
                    {$content}

                <h2>🔍 Scope Comparison</h2>
                <table>
                    <tr>
                        <th>Scope</th>
                        <th>Detection</th>
                        <th>Cache</th>
                        <th>Shared Across</th>
                        <th>Example Above</th>
                    </tr>
                    <tr>
                        <td><strong>GLOBAL</strong></td>
                        <td>Only global actions</td>
                        <td>App-wide (one cache)</td>
                        <td>ALL routes, ALL users</td>
                        <td>System Status Banner</td>
                    </tr>
                    <tr>
                        <td><strong>ROUTE</strong></td>
                        <td>Only route actions</td>
                        <td>Per route</td>
                        <td>Same route, ALL users</td>
                        <td>Shared Page Counter</td>
                    </tr>
                    <tr>
                        <td><strong>TAB</strong></td>
                        <td>Uses signals or mixed</td>
                        <td>No cache</td>
                        <td>Single tab only</td>
                        <td>Personal Message</td>
                    </tr>
                </table>

                <h2>🧪 Try This!</h2>
                <ol>
                    <li><strong>Global Scope Test:</strong> Open multiple tabs on different pages, click "Update Global Status" - watch ALL tabs update</li>
                    <li><strong>Route Scope Test:</strong> Open multiple tabs on the SAME page, click "Increment" - watch all tabs of that page update</li>
                    <li><strong>Tab Scope Test:</strong> Type different messages in different tabs - each tab keeps its own message</li>
                    <li><strong>Cross-page test:</strong> Notice the Route counter is different on each page, but Global status is always the same</li>
                </ol>
                </div>
            </div>
            HTML;
        });
    });
};

// =============================================================================
// Register pages
// =============================================================================
$createPage('🏠 Home', '/', <<<'HTML'
    <p>Welcome to the <strong>All Scopes Demo</strong>!</p>
    <p>This application demonstrates all three scopes in php-via:</p>
    <ul>
        <li><strong>GLOBAL</strong>: The system status banner at the top is shared across EVERY page</li>
        <li><strong>ROUTE</strong>: The page counter is shared by all users on THIS page only</li>
        <li><strong>TAB</strong>: Your personal message is unique to your tab</li>
    </ul>
    <p>Navigate to Page A and Page B to see how the different scopes behave!</p>
HTML);

$createPage('📄 Page A', '/page-a', <<<'HTML'
    <p>This is <strong>Page A</strong>.</p>
    <p>Notice:</p>
    <ul>
        <li>✅ Global status banner shows the SAME status as Home page</li>
        <li>✅ Route counter is DIFFERENT from Home (this is Page A's counter)</li>
        <li>✅ Your personal message is unique to this tab</li>
    </ul>
    <p>Open this page in multiple tabs and click "Increment" to see route scope in action!</p>
HTML);

$createPage('📄 Page B', '/page-b', <<<'HTML'
    <p>This is <strong>Page B</strong>.</p>
    <p>Notice:</p>
    <ul>
        <li>✅ Global status banner is STILL the same across all pages</li>
        <li>✅ Route counter is DIFFERENT from Home and Page A (this is Page B's counter)</li>
        <li>✅ Your personal message is still unique to your tab</li>
    </ul>
    <p>Try updating the global status from here - it will update on ALL pages!</p>
HTML);

echo "Starting All Scopes Demo on http://0.0.0.0:3012\n";
echo "Open multiple tabs and pages to see how each scope behaves!\n";
echo "\n";
echo "Pages available:\n";
echo "  - http://0.0.0.0:3012/       (Home)\n";
echo "  - http://0.0.0.0:3012/page-a (Page A)\n";
echo "  - http://0.0.0.0:3012/page-b (Page B)\n";
echo "\n";
$app->start();
