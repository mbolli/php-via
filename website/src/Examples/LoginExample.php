<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Login Flow Example.
 *
 * Demonstrates per-session authentication state using sessionData.
 * The key insight: sessionData survives page navigations and context
 * destruction, so the user stays logged in across refreshes — something
 * a plain signal cannot do.
 */
final class LoginExample {
    public const string SLUG = 'login';

    /** @var array<string, array{password: string, role: string, name: string}> */
    private const array USERS = [
        'ada' => ['password' => 'lovelace', 'role' => 'Engineer', 'name' => 'Ada Lovelace'],
        'grace' => ['password' => 'hopper', 'role' => 'Admiral', 'name' => 'Grace Hopper'],
        'linus' => ['password' => 'torvalds', 'role' => 'Maintainer', 'name' => 'Linus Torvalds'],
    ];

    public static function register(Via $app): void {
        $app->page('/examples/login', function (Context $c): void {
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
                $c->sync();
            }, 'login');

            $logout = $c->action(function () use ($usernameInput, $passwordInput, $errorMsg, $c): void {
                $c->clearSessionData('auth');
                $usernameInput->setValue('');
                $passwordInput->setValue('');
                $errorMsg->setValue('');
                $c->sync();
            }, 'logout');

            $c->view(function () use ($c, $usernameInput, $passwordInput, $errorMsg, $login, $logout): string {
                /** @var null|array{user: string, name: string, role: string, at: int} $auth */
                $auth = $c->sessionData('auth');

                return $c->render('examples/login.html.twig', [
                    'title' => '🔐 Login Flow',
                    'description' => 'Persistent login state across page refreshes — using sessionData, not signals. Refresh the page while logged in; you stay logged in because the auth record lives in server-side session storage, not in a tab-local signal.',
                    'summary' => [
                        '<strong>Signals reset on refresh; sessionData does not.</strong> When you refresh, the old Context is destroyed and a new one is created — all signal values go back to their defaults. But <code>sessionData</code> is keyed on the session cookie, so it survives across any number of new Contexts.',
                        '<strong>Login sets <code>sessionData(\'auth\')</code></strong> — the action validates credentials, writes the auth record server-side, and syncs. No JWT, no client-side cookie handling, no localStorage.',
                        '<strong>Every page render reads the session.</strong> The view closure checks <code>$c->sessionData(\'auth\')</code> on every render. Logged in → show dashboard. Logged out → show login form.',
                        '<strong>Logout calls <code>clearSessionData(\'auth\')</code></strong> — the server-side record is wiped immediately. No client-side token to steal or forge.',
                    ],
                    'anatomy' => [
                        'signals' => [
                            ['name' => 'username', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Username input. Only used during login; cleared after successful authentication.'],
                            ['name' => 'password', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Password input. Cleared on login success or failure.'],
                            ['name' => 'error', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Validation error message shown beneath the login form.'],
                        ],
                        'actions' => [
                            ['name' => 'login', 'desc' => 'Validates credentials against the hardcoded user table. On success, writes auth record to sessionData. On failure, sets the error signal.'],
                            ['name' => 'logout', 'desc' => 'Clears sessionData(\'auth\') server-side and resets all form signals.'],
                        ],
                        'views' => [
                            ['name' => 'login.html.twig', 'desc' => 'Reads auth from sessionData on every render. Logged-in → shows the user dashboard. Logged-out → shows the login form. Refreshing the page keeps the correct state.'],
                        ],
                    ],
                    'githubLinks' => [
                        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/LoginExample.php'],
                        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/login.html.twig'],
                    ],
                    'auth' => $auth,
                    'usernameInput' => $usernameInput,
                    'passwordInput' => $passwordInput,
                    'errorMsg' => $errorMsg,
                    'login' => $login,
                    'logout' => $logout,
                    'users' => array_map(
                        fn (string $k, array $u): array => ['username' => $k, 'password' => $u['password'], 'name' => $u['name'], 'role' => $u['role']],
                        array_keys(self::USERS),
                        self::USERS,
                    ),
                ]);
            }, block: 'demo', cacheUpdates: false);
        });
    }
}
