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
 *  - /examples/login            — public login form (no middleware)
 *  - /examples/login/dashboard  — protected by AuthMiddleware
 *  - /examples/login/profile    — protected by AuthMiddleware
 *
 * The two protected routes are registered inside Via::group(), so a single
 * ->middleware($authMiddleware) call covers both of them.
 * AuthMiddleware reads sessionData('auth') from the session cookie and either
 * redirects to the login page or passes the auth record as a request attribute.
 * The dashboard handler reads it via $c->getRequestAttribute('auth').
 */
final class LoginExample {
    public const string SLUG = 'login';

    private const string TITLE = '🔐 Login Flow';

    private const string DESCRIPTION = 'PSR-15 middleware-based authentication. The login form is public; the dashboard and profile routes are protected by <code>AuthMiddleware</code> via <code>Via::group()->middleware()</code>.';

    private const array SUMMARY = [
        '<strong>Three routes, one middleware.</strong> <code>/examples/login</code> is public. Dashboard and profile are protected via <code>Via::group()->middleware(new AuthMiddleware(...))</code> — one call shields both.',
        '<strong>AuthMiddleware reads the session cookie</strong> from the PSR-7 request, looks up <code>sessionData(\'auth\')</code> in the server-side session store, and either redirects (302) or passes the auth record downstream as a request attribute.',
        '<strong>The handlers read <code>$c->getRequestAttribute(\'auth\')</code></strong> — the middleware-set attribute is automatically bridged from the PSR-7 request to the Via Context. No manual session checks needed.',
        '<strong>Logout clears the session</strong> and redirects back to the login form. The middleware will block any subsequent protected access until login succeeds again.',
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
                $ctx->setSessionData('auth', [
                    'user' => $user,
                    'name' => $record['name'],
                    'role' => $record['role'],
                    'at' => time(),
                ]);

                // Redirect to the protected dashboard
                $ctx->execScript("window.location.href = '/examples/login/dashboard'");
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
                'users' => array_map(
                    fn (string $k, array $u): array => ['username' => $k, 'password' => $u['password'], 'name' => $u['name'], 'role' => $u['role']],
                    array_keys(self::USERS),
                    self::USERS,
                ),
            ]), block: 'demo', cacheUpdates: false);
        });

        // ── Protected routes (dashboard + profile) behind AuthMiddleware ──
        $app->group(function (Via $app): void {
            $app->page('/examples/login/dashboard', function (Context $c): void {
                /** @var array{user: string, name: string, role: string, at: int} $auth */
                $auth = $c->getRequestAttribute('auth');

                $logout = $c->action(function (Context $ctx): void {
                    $ctx->clearSessionData('auth');
                    $ctx->execScript("window.location.href = '/examples/login'");
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
                ]), block: 'demo', cacheUpdates: false);
            });

            $app->page('/examples/login/profile', function (Context $c): void {
                /** @var array{user: string, name: string, role: string, at: int} $auth */
                $auth = $c->getRequestAttribute('auth');

                $logout = $c->action(function (Context $ctx): void {
                    $ctx->clearSessionData('auth');
                    $ctx->execScript("window.location.href = '/examples/login'");
                }, 'logout');

                $c->view(fn (): string => $c->render('examples/login_profile.html.twig', [
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
                ]), block: 'demo', cacheUpdates: false);
            });
        })->middleware($authMiddleware);
    }
}
