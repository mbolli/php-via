<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * Global Notifications Example.
 *
 * Demonstrates GLOBAL scope - a notification system that appears on ALL pages.
 * This shows how global state and global actions work across different routes.
 *
 * Features:
 * - Global notification count visible on every page
 * - Clicking "Add Notification" on ANY page updates ALL pages
 * - Single render cached globally (extreme performance)
 * - Uses globalAction() and globalState()
 */

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3008)
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

// Shared notification component (uses GLOBAL scope)
$notificationBanner = function (Context $c) use ($app): void {
    // Set GLOBAL scope - shared across ALL routes and users
    $c->scope(Scope::GLOBAL);

    // This action is GLOBAL - callable from any route, affects all routes
    $addNotification = $c->action(function (Context $ctx) use ($app): void {
        $count = $app->globalState('notificationCount', 0);
        $app->setGlobalState('notificationCount', $count + 1);
        $app->log('info', 'Notification added! Total: ' . ($count + 1));

        // Broadcast to ALL routes
        $app->broadcast(Scope::GLOBAL);
    }, 'addNotification');

    $clearNotifications = $c->action(function (Context $ctx) use ($app): void {
        $app->setGlobalState('notificationCount', 0);
        $app->log('info', 'Notifications cleared');
        $app->broadcast(Scope::GLOBAL);
    }, 'clearNotifications');

    // Note: No signals, ONLY global actions = GLOBAL scope
    // This view will be cached globally and shared across ALL routes!
    $c->view(function () use ($app, $addNotification, $clearNotifications): string {
        $count = $app->globalState('notificationCount', 0);

        return <<<HTML
        <div class="card" style="background: var(--color-light); margin-bottom: 1rem;">
            <div class="flex" style="justify-content: space-between; align-items: center;">
                <div>
                    <strong>🔔 Notifications:</strong>
                    <span class="badge" style="background: var(--color-danger); color: white; font-size: 0.9rem;">
                        {$count}
                    </span>
                </div>
                <div>
                    <button
                        data-on:click="@get('{$addNotification->url()}')"
                        style="margin-right: 0.5rem;">
                        ➕ Add Notification
                    </button>
                    <button
                        data-on:click="@get('{$clearNotifications->url()}')">
                        🗑️ Clear
                    </button>
                </div>
            </div>
            <p class="mt-2" style="font-size: 0.9rem; color: #666;">
                <em>This banner uses GLOBAL scope - it's rendered once and shared across ALL pages!
                Click "Add Notification" on any page to update everywhere.</em>
            </p>
        </div>
        HTML;
    });
};

// Page 1 - Home
$app->page('/', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Home</title>
        <link rel="stylesheet" href="/_via.css">
        <style>
            nav { margin-bottom: 2rem; display: flex; gap: 1rem; }
            nav a { padding: 0.5rem 1rem; background: var(--color-success); color: white; text-decoration: none; border-radius: var(--border-radius); font-weight: 600; transition: all 0.2s; }
            nav a:hover { background: var(--color-dark); }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <div class="container" id="content">
            <h1>🔔 Global Notifications</h1>
            <nav>
                <a href="/">🏠 Home</a>
                <a href="/dashboard">📊 Dashboard</a>
                <a href="/settings">⚙️ Settings</a>
            </nav>

            {$banner()}

            <div class="card">
                <h1>🏠 Home Page</h1>
                <p>This is the home page. Notice how the notification banner appears on every page.</p>
                <p>Try this:</p>
                <ol>
                    <li>Open this page in multiple tabs</li>
                    <li>Navigate to different pages (Dashboard, Settings)</li>
                    <li>Click "Add Notification" on any tab/page</li>
                    <li>Watch ALL tabs on ALL pages update instantly!</li>
                </ol>
                <p><strong>How it works:</strong></p>
                <ul>
                    <li>The notification banner uses <code>globalAction()</code> (not routeAction)</li>
                    <li>Global state stored via <code>setGlobalState()</code></li>
                    <li>Updates broadcast via <code>broadcastGlobal()</code></li>
                    <li>The view is rendered ONCE and cached globally</li>
                    <li>All routes share the same cached HTML (max performance!)</li>
                </ul>
            </div>
        </div>
    HTML);
});

// Page 2 - Dashboard
$app->page('/dashboard', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Dashboard</title>
        <style>
            nav { margin-bottom: 2rem; display: flex; gap: 1rem; }
            nav a { padding: 0.5rem 1rem; background: var(--color-success); color: white; text-decoration: none; border-radius: var(--border-radius); font-weight: 600; transition: all 0.2s; }
            nav a:hover { background: var(--color-dark); }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <div class="container" id="content">
            <h1>🔔 Global Notifications</h1>
            <nav>
                <a href="/">🏠 Home</a>
                <a href="/dashboard">📊 Dashboard</a>
                <a href="/settings">⚙️ Settings</a>
            </nav>

            {$banner()}

            <div class="card">
                <h1>📊 Dashboard Page</h1>
                <p>This is the dashboard. The same global notification banner is shared here!</p>
                <p><strong>Key point:</strong> When you click "Add Notification" here, it updates the banner on:</p>
                <ul>
                    <li>✅ This dashboard page</li>
                    <li>✅ The home page</li>
                    <li>✅ The settings page</li>
                    <li>✅ ALL open tabs, regardless of which page they're on</li>
                </ul>
            </div>
        </div>
    HTML);
});

// Page 3 - Settings
$app->page('/settings', function (Context $c) use ($app, $notificationBanner): void {
    $banner = $c->component($notificationBanner, 'notifications');

    $app->appendToHead(<<<'HTML'
        <title>🌍 Global Scope Demo - Settings</title>
        <style>
            nav { margin-bottom: 2rem; display: flex; gap: 1rem; }
            nav a { padding: 0.5rem 1rem; background: var(--color-success); color: white; text-decoration: none; border-radius: var(--border-radius); font-weight: 600; transition: all 0.2s; }
            nav a:hover { background: var(--color-dark); }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 12px; text-align: left; border: 1px solid var(--color-light); }
            th { background: var(--color-dark); color: white; font-weight: 600; }
            tr:nth-child(even) { background: var(--color-light); }
        </style>
    HTML);

    $c->view(fn (): string => <<<HTML
        <div class="container" id="content">
            <h1>🔔 Global Notifications</h1>
            <nav>
                <a href="/">🏠 Home</a>
                <a href="/dashboard">📊 Dashboard</a>
                <a href="/settings">⚙️ Settings</a>
            </nav>

            {$banner()}

            <div class="card">
                <h1>⚙️ Settings Page</h1>
                <p>This is the settings page. The global notification system works here too!</p>
                <p><strong>Scope comparison:</strong></p>
                <table>
                    <tr>
                        <th>Scope</th>
                        <th>Cache Key</th>
                        <th>Shared Across</th>
                        <th>Example</th>
                    </tr>
                    <tr>
                        <td><strong>GLOBAL</strong></td>
                        <td>App-wide (one cache for entire app)</td>
                        <td>ALL routes, ALL users</td>
                        <td>This notification banner</td>
                    </tr>
                    <tr>
                        <td><strong>ROUTE</strong></td>
                        <td>Per route (e.g., <code>/game</code>)</td>
                        <td>Same route, ALL users</td>
                        <td>Game of Life board</td>
                    </tr>
                    <tr>
                        <td><strong>TAB</strong></td>
                        <td>No cache</td>
                        <td>Single user/tab only</td>
                        <td>Personal settings page</td>
                    </tr>
                </table>
            </div>
        </div>
    HTML);
});

echo "Starting Global Scope Demo on http://0.0.0.0:3008\n";
echo "Try opening multiple pages in different tabs and clicking 'Add Notification'!\n";
$app->start();
