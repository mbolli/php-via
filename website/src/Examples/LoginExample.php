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
 *  - /examples/login:           public login form (no middleware)
 *  - /examples/login/dashboard: protected by AuthMiddleware
 *  - /examples/login/profile:   protected by AuthMiddleware
 *
 * The two protected routes are registered inside Via::group(), so a single
 * ->middleware($authMiddleware) call covers both of them.
 * AuthMiddleware reads sessionData('auth') for the session in the request's
 * 'via.session' attribute and either redirects to the login page or passes the
 * auth record as a request attribute.
 * The dashboard handler reads it via $c->getRequestAttribute('auth').
 */
final class LoginExample {
    public const string SLUG = 'login';

    private const string TITLE = 'Login Flow';

    private const string DESCRIPTION = 'Log in with one of the demo accounts to reach the dashboard and profile pages, then log out. Those two routes sit in a <code>Via::group()</code> behind a PSR-15 <code>AuthMiddleware</code>; the login form is public.';

    private const array SUMMARY = [
        '<strong>Three routes, one middleware.</strong> <code>/examples/login</code> is public. Dashboard and profile are protected via <code>Via::group()->middleware(new AuthMiddleware(...))</code>: one call shields both.',
        '<strong>AuthMiddleware reads the session id</strong> from the request\'s <code>via.session</code> attribute, looks up <code>sessionData(\'auth\')</code> in the server-side session store, and either redirects (302) or passes the auth record downstream as a request attribute. It runs again when a tab that was away comes back.',
        '<strong>The handlers read <code>$c->getRequestAttribute(\'auth\')</code></strong>: the middleware-set attribute is automatically bridged from the PSR-7 request to the Via Context. No manual session checks needed.',
        '<strong>Login and logout rotate the session cookie</strong> with <code>$c->regenerateSession()</code>, so a cookie someone planted or read before stops reaching the account 10 seconds later. Logout also clears <code>sessionData(\'auth\')</code>, and the middleware blocks protected pages until the next login.',
    ];

    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LoginExample.php'],
        ['label' => 'View login template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login.html.twig'],
        ['label' => 'View dashboard template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login_dashboard.html.twig'],
        ['label' => 'View profile template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login_profile.html.twig'],
        ['label' => 'View AuthMiddleware', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Middleware/AuthMiddleware.php'],
    ];

    private const array VIEWS_ANATOMY = [
        ['name' => 'login.html.twig', 'desc' => 'Login form. Redirects to dashboard if already authenticated.'],
        ['name' => 'login_dashboard.html.twig', 'desc' => 'Protected dashboard. Auth data injected by AuthMiddleware via request attributes.'],
        ['name' => 'login_profile.html.twig', 'desc' => 'Protected profile page. Same AuthMiddleware protects it via the shared group.'],
    ];

    private const array MIDDLEWARE_ANATOMY = [
        ['name' => 'AuthMiddleware', 'desc' => 'PSR-15 middleware that checks sessionData(\'auth\') and redirects unauthenticated requests to the login form. As route middleware it runs on page loads and when a tab that was away comes back, not on actions or SSE connects: a dashboard tab left open at logout keeps its stream until it reloads.'],
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

            $c->signal('', 'username');
            $c->signal('', 'password');
            $c->signal('', 'error');

            $c->action(function (Context $ctx): void {
                $usernameInput = $ctx->getSignal('username');
                $passwordInput = $ctx->getSignal('password');
                $errorMsg = $ctx->getSignal('error');

                $user = mb_strtolower(mb_trim($usernameInput->string()));
                $pass = $passwordInput->string();

                $record = self::USERS[$user] ?? null;

                if ($record === null || $record['password'] !== $pass) {
                    $errorMsg->setValue('Invalid username or password.');
                    $passwordInput->setValue('');
                    $ctx->sync();

                    return;
                }

                $errorMsg->setValue('');
                $ctx->regenerateSession();
                $ctx->setSessionData('auth', [
                    'user' => $user,
                    'name' => $record['name'],
                    'role' => $record['role'],
                    'at' => time(),
                ]);

                // Redirect to the protected dashboard
                $ctx->execScript("window.location.href = '/examples/login/dashboard'");
            }, 'login');

            $c->view('examples/login.html.twig', fn (): array => [
                'title' => self::TITLE,
                'description' => self::DESCRIPTION,
                'summary' => self::SUMMARY,
                'anatomy' => [
                    'signals' => [
                        ['name' => 'username', 'type' => 'string', 'scope' => 'TAB', 'default' => '', 'desc' => 'Username input bound to the login form.'],
                        ['name' => 'password', 'type' => 'string', 'scope' => 'TAB', 'default' => '', 'desc' => 'Password input. Cleared on failure.'],
                        ['name' => 'error', 'type' => 'string', 'scope' => 'TAB', 'default' => '', 'desc' => 'Validation error message shown beneath the form.'],
                    ],
                    'actions' => [
                        ['name' => 'login', 'desc' => 'Validates credentials. On success, rotates the session cookie, writes auth to sessionData and redirects to the middleware-protected dashboard.'],
                    ],
                    'views' => self::VIEWS_ANATOMY,
                    'middleware' => self::MIDDLEWARE_ANATOMY,
                ],
                'githubLinks' => self::GITHUB_LINKS,
                'users' => array_map(
                    fn (string $k, array $u): array => ['username' => $k, 'password' => $u['password'], 'name' => $u['name'], 'role' => $u['role']],
                    array_keys(self::USERS),
                    self::USERS,
                ),
            ], block: 'demo');
        });

        // ── Protected routes (dashboard + profile) behind AuthMiddleware ──
        $app->group(function (Via $app): void {
            $app->page('/examples/login/dashboard', function (Context $c): void {
                $auth = self::auth($c);
                if ($auth === null) {
                    $c->view(static fn (): string => '<div id="login-demo"></div>');

                    return;
                }

                $logout = $c->action(function (Context $ctx): void {
                    $ctx->clearSessionData('auth');
                    $ctx->regenerateSession();
                    $ctx->execScript("window.location.href = '/examples/login'");
                }, 'logout');

                $c->view('examples/login_dashboard.html.twig', fn (): array => [
                    'title' => self::TITLE,
                    'description' => self::DESCRIPTION,
                    'summary' => self::SUMMARY,
                    'anatomy' => [
                        'signals' => [],
                        'actions' => [
                            ['name' => 'logout', 'desc' => 'Clears sessionData(\'auth\'), rotates the session cookie and redirects to the login form.'],
                        ],
                        'views' => self::VIEWS_ANATOMY,
                        'middleware' => self::MIDDLEWARE_ANATOMY,
                    ],
                    'githubLinks' => self::GITHUB_LINKS,
                    'auth' => $auth,
                ], block: 'demo');
            });

            $app->page('/examples/login/profile', function (Context $c): void {
                $auth = self::auth($c);
                if ($auth === null) {
                    $c->view(static fn (): string => '<div id="login-demo"></div>');

                    return;
                }

                $logout = $c->action(function (Context $ctx): void {
                    $ctx->clearSessionData('auth');
                    $ctx->regenerateSession();
                    $ctx->execScript("window.location.href = '/examples/login'");
                }, 'logout');

                $c->view('examples/login_profile.html.twig', fn (): array => [
                    'title' => self::TITLE,
                    'description' => self::DESCRIPTION,
                    'summary' => self::SUMMARY,
                    'anatomy' => [
                        'signals' => [],
                        'actions' => [
                            ['name' => 'logout', 'desc' => 'Clears sessionData(\'auth\'), rotates the session cookie and redirects to the login form.'],
                        ],
                        'views' => self::VIEWS_ANATOMY,
                        'middleware' => self::MIDDLEWARE_ANATOMY,
                    ],
                    'githubLinks' => self::GITHUB_LINKS,
                    'auth' => $auth,
                ], block: 'demo');
            });
        })->middleware($authMiddleware);
    }

    /**
     * The auth record AuthMiddleware set on the page request, or on the request that rebuilt a tab that was away.
     *
     * @return null|array{user: string, name: string, role: string, at: int}
     */
    private static function auth(Context $c): ?array {
        /** @var null|array{user: string, name: string, role: string, at: int} $auth */
        $auth = $c->getRequestAttribute('auth');
        if ($auth === null) {
            $c->execScript("window.location.href = '/examples/login'");
        }

        return $auth;
    }
}
