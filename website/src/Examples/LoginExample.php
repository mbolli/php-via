<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Middleware\AuthMiddleware;

/**
 * Login Flow Example.
 *
 * Demonstrates PSR-15 middleware-based authentication:
 *  - /examples/login          — public login form (no middleware)
 *  - /examples/login/dashboard — protected by AuthMiddleware (redirects to login if unauthenticated)
 *
 * The AuthMiddleware reads sessionData('auth') from the session cookie and either
 * redirects to the login page or passes the auth record as a request attribute.
 * The dashboard handler reads it via $c->getRequestAttribute('auth').
 */
final class LoginExample {
    public const string SLUG = 'login';

    private const string TITLE = '🔐 Login Flow';

    private const string DESCRIPTION = 'PSR-15 middleware-based authentication. The login form is public; the dashboard route is protected by an <code>AuthMiddleware</code> that checks <code>sessionData(\'auth\')</code> and redirects unauthenticated users back here.';

    private const array SUMMARY = [
        '<strong>Two routes, one middleware.</strong> <code>/examples/login</code> is public. <code>/examples/login/dashboard</code> is protected by <code>AuthMiddleware</code> — a real PSR-15 middleware attached via <code>->middleware()</code>.',
        '<strong>AuthMiddleware reads the session cookie</strong> from the PSR-7 request, looks up <code>sessionData(\'auth\')</code> in the server-side session store, and either redirects (302) or passes the auth record downstream as a request attribute.',
        '<strong>The dashboard reads <code>$c->getRequestAttribute(\'auth\')</code></strong> — the middleware-set attribute is automatically bridged from the PSR-7 request to the Via Context. No manual session checks needed in the page handler.',
        '<strong>Logout clears the session</strong> and redirects back to the login form. The middleware will block any subsequent dashboard access until login succeeds again.',
    ];

    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LoginExample.php'],
        ['label' => 'View login template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login.html.twig'],
        ['label' => 'View dashboard template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login_dashboard.html.twig'],
        ['label' => 'View AuthMiddleware', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Middleware/AuthMiddleware.php'],
    ];

    private const array VIEWS_ANATOMY = [
        ['name' => 'login.html.twig', 'desc' => 'Login form. Redirects to dashboard if already authenticated.'],
        ['name' => 'login_dashboard.html.twig', 'desc' => 'Protected dashboard. Auth data injected by AuthMiddleware via request attributes.'],
    ];

    private const array MIDDLEWARE_ANATOMY = [
        ['name' => 'AuthMiddleware', 'desc' => 'PSR-15 middleware that checks sessionData(\'auth\') and redirects unauthenticated requests to the login form. Implements SseAwareMiddleware to also protect SSE connections.'],
    ];

    /** @var array<string, array{password: string, role: string, name: string}> */
    private const array USERS = [
        'ada' => ['password' => 'lovelace', 'role' => 'Engineer', 'name' => 'Ada Lovelace'],
        'grace' => ['password' => 'hopper', 'role' => 'Admiral', 'name' => 'Grace Hopper'],
        'linus' => ['password' => 'torvalds', 'role' => 'Maintainer', 'name' => 'Linus Torvalds'],
    ];

    public static function register(Via $app): void {
        $authMiddleware = new AuthMiddleware($app, '/examples/login');

        // ── Public login form ────────────────────────────────────────────
        $app->page('/examples/login', function (Context $c): void {
            // If already logged in, redirect to dashboard
            /** @var null|array{user: string, name: string, role: string, at: int} $auth */
            $auth = $c->sessionData('auth');
            if ($auth !== null) {
                $c->execScript("window.location.href = '/examples/login/dashboard'");
            }

            $usernameInput = $c->signal('', 'username');
            $passwordInput = $c->signal('', 'password');
            $errorMsg = $c->signal('', 'error');

            $login = $c->action(function () use ($usernameInput, $passwordInput, $errorMsg, $c): void {
                $user = mb_strtolower(mb_trim($usernameInput->string()));
                $pass = $passwordInput->string();

                $record = self::USERS[$user] ?? null;

                if ($record === null || $record['password'] !== $pass) {
                    $errorMsg->setValue('Invalid username or password.');
                    $passwordInput->setValue('');
                    $c->sync();

                    return;
                }

                $errorMsg->setValue('');
                $c->setSessionData('auth', [
                    'user' => $user,
                    'name' => $record['name'],
                    'role' => $record['role'],
                    'at' => time(),
                ]);

                // Redirect to the protected dashboard
                $c->execScript("window.location.href = '/examples/login/dashboard'");
            }, 'login');

            $c->view(fn (): string => $c->render('examples/login.html.twig', [
                'title' => self::TITLE,
                'description' => self::DESCRIPTION,
                'summary' => self::SUMMARY,
                'anatomy' => [
                    'signals' => [
                        ['name' => 'username', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Username input bound to the login form.'],
                        ['name' => 'password', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Password input. Cleared on failure.'],
                        ['name' => 'error', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Validation error message shown beneath the form.'],
                    ],
                    'actions' => [
                        ['name' => 'login', 'desc' => 'Validates credentials. On success, writes auth to sessionData and redirects to the middleware-protected dashboard.'],
                    ],
                    'views' => self::VIEWS_ANATOMY,
                    'middleware' => self::MIDDLEWARE_ANATOMY,
                ],
                'githubLinks' => self::GITHUB_LINKS,
                'usernameInput' => $usernameInput,
                'passwordInput' => $passwordInput,
                'errorMsg' => $errorMsg,
                'login' => $login,
                'users' => array_map(
                    fn (string $k, array $u): array => ['username' => $k, 'password' => $u['password'], 'name' => $u['name'], 'role' => $u['role']],
                    array_keys(self::USERS),
                    self::USERS,
                ),
            ]), block: 'demo', cacheUpdates: false);
        });

        // ── Protected dashboard (behind AuthMiddleware) ──────────────────
        $app->page('/examples/login/dashboard', function (Context $c): void {
            /** @var array{user: string, name: string, role: string, at: int} $auth */
            $auth = $c->getRequestAttribute('auth');

            $logout = $c->action(function () use ($c): void {
                $c->clearSessionData('auth');
                $c->execScript("window.location.href = '/examples/login'");
            }, 'logout');

            $c->view(fn (): string => $c->render('examples/login_dashboard.html.twig', [
                'title' => self::TITLE,
                'description' => self::DESCRIPTION,
                'summary' => self::SUMMARY,
                'anatomy' => [
                    'signals' => [],
                    'actions' => [
                        ['name' => 'logout', 'desc' => 'Clears sessionData(\'auth\') and redirects to the login form.'],
                    ],
                    'views' => self::VIEWS_ANATOMY,
                    'middleware' => self::MIDDLEWARE_ANATOMY,
                ],
                'githubLinks' => self::GITHUB_LINKS,
                'auth' => $auth,
                'logout' => $logout,
            ]), block: 'demo', cacheUpdates: false);
        })->middleware($authMiddleware);
    }
}
