<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Router;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * Route parameters arrive percent-decoded. The path is matched as the client sent it, so an encoded slash stays in
 * its segment and arrives as '/'.
 */

test('a route parameter is decoded after matching, and an encoded slash stays in its parameter', function (string $path, string $expected): void {
    $router = new Router();
    $router->registerRoute('/files/{name}', static fn () => null);
    $params = [];

    expect($router->matchRoute($path, $params))->not->toBeNull()
        ->and($params)->toBe(['name' => $expected])
    ;
})->with([
    ['/files/report.pdf', 'report.pdf'],
    ['/files/a%20b', 'a b'],
    ['/files/caf%C3%A9', 'café'],
    ['/files/a%2Fb', 'a/b'],
    ['/files/100%25', '100%'],
    ['/files/a+b', 'a+b'],
]);

test('a page handler and a route() handler get decoded parameters', function (): void {
    $app = new TestApp((new Config())->withLogLevel('error'), static function (Via $via): void {
        $via->page('/blog/{slug}', static function (Context $c, string $slug): void {
            $c->view(static fn (): string => '<p id="slug">' . htmlspecialchars($slug) . '</p>');
        });
        $via->route('GET', '/api/{id}', new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface {
                return new Psr7Response(200, [], (string) $request->getAttribute('id'));
            }
        });
    });

    expect($app->open('/blog/caf%C3%A9%2Fbar', connect: false)->html())->toContain('<p id="slug">café/bar</p>')
        ->and((string) $app->request('GET', '/api/a%20b')->getBody())->toBe('a b')
    ;
});
