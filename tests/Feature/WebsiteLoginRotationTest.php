<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Testing\TestRequest;
use Mbolli\PhpVia\Testing\TestResponse;
use Mbolli\PhpVia\Testing\TestTab;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Examples\LoginExample;

/*
 * The website's login example rotates the session cookie at login and logout, so a cookie planted
 * before the login never reaches the dashboard.
 */

$loginAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$loginReady = false;

if (is_file($loginAutoload)) {
    require_once $loginAutoload;
    $loginReady = class_exists('PhpVia\\Website\\Examples\\LoginExample');
}

if (!$loginReady) {
    test('website login rotation (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

function websiteLoginApp(int &$now): TestApp {
    $app = new TestApp(
        (new Config())->withLogLevel('error')->withTemplateDir(dirname(__DIR__, 2) . '/website/templates'),
        static function (Via $via): void {
            foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
                $via->getTwig()->addGlobal($name, $value);
            }
            LoginExample::register($via);
        },
    );
    $via = $app->via();
    $via->getSessionManager()->useTokens(new SessionTokens(64, static fn (string $key): bool => $via->getApp()->hasSessionData($key), clock: static function () use (&$now): int {
        return $now;
    }));

    return $app;
}

function websiteLoginDashboard(TestApp $app, string $cookie): int {
    $response = new TestResponse();
    $app->send(new TestRequest('GET', '/examples/login/dashboard', [], [SessionManager::SESSION_COOKIE_NAME => $cookie]), $response);

    return $response->statusCode;
}

/** The session cookie the browser of $tab holds now. */
function websiteLoginCookie(TestTab $tab): string {
    return (string) $tab->open('/examples/login', connect: false)->context()->cookie(SessionManager::SESSION_COOKIE_NAME);
}

test('the login example rotates the session cookie at login and logout, so an earlier cookie never reaches the dashboard', function (): void {
    $now = 1_000_000;
    $app = websiteLoginApp($now);
    $tab = $app->open('/examples/login');
    $planted = websiteLoginCookie($tab);

    $tab->action('login', signals: ['username' => 'ada', 'password' => 'lovelace']);
    $loggedIn = websiteLoginCookie($tab);
    $inGrace = websiteLoginDashboard($app, $planted);
    $now += SessionTokens::GRACE_SECONDS;

    expect($planted)->not->toBe('')
        ->and($loggedIn)->not->toBe($planted)
        ->and($inGrace)->toBe(302)
        ->and(websiteLoginDashboard($app, $planted))->toBe(302)
        ->and(websiteLoginDashboard($app, $loggedIn))->toBe(200)
    ;

    $tab->open('/examples/login/dashboard')->action('logout');
    $loggedOut = websiteLoginCookie($tab);

    expect($loggedOut)->not->toBe($loggedIn)
        ->and(websiteLoginDashboard($app, $loggedOut))->toBe(302)
    ;
});

test('a dashboard tab rebuilt after it was away still shows the user, and goes to the login form after a logout in another tab', function (): void {
    $now = 1_000_000;
    $app = websiteLoginApp($now);
    $login = $app->open('/examples/login', connect: false);
    $login->action('login', signals: ['username' => 'grace', 'password' => 'hopper']);
    $dashboard = $login->open('/examples/login/dashboard');
    $other = $login->open('/examples/login/dashboard');
    $dashboard->patches();

    $dashboard->disconnect(expire: true)->connect();
    $revived = json_encode($dashboard->patches(), JSON_UNESCAPED_SLASHES);
    $other->action('logout');
    $dashboard->disconnect(expire: true)->connect();
    $afterLogout = json_encode($dashboard->patches(), JSON_UNESCAPED_SLASHES);

    // The route's AuthMiddleware runs again for the rebuild and refuses it, so the tab reloads into the login form.
    expect($revived)->toContain('Grace Hopper')
        ->and($afterLogout)->not->toContain('Grace Hopper')
        ->and($afterLogout)->toContain('window.location.reload()')
    ;
});
