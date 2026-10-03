<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Rendering\TemplateEngine;
use Mbolli\PhpVia\Twig\TwigEngine;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeStaticResponse;
use Twig\Error\LoaderError;

/*
 * Template views go through the TemplateEngine from Config::withTemplateEngine() or
 * withTemplateDir(); without one, a template view throws when it is declared.
 */

/**
 * An engine that records its calls and renders "template[block]" plus the scalar data it got.
 */
final class RecordingEngine implements TemplateEngine {
    /** @var list<array{template: string, data: array<string, mixed>, block: ?string}> */
    public array $calls = [];

    public function __construct(private bool $blocks = true) {}

    public function render(string $template, array $data, ?string $block = null): string {
        $this->calls[] = ['template' => $template, 'data' => $data, 'block' => $block];

        return $template . ($block === null ? '' : "[{$block}]");
    }

    public function supportsBlocks(): bool {
        return $this->blocks;
    }
}

function templatePageResponse(Via $via, string $path): FakeStaticResponse {
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $handler->setRoutes($via->getRouter()->getRoutes());
    $request = new Request();
    $request->server = ['request_uri' => $path, 'request_method' => 'GET'];
    $request->header = [];
    $response = new FakeStaticResponse();

    ob_start();

    try {
        $handler->handleRequest($request, $response);
    } finally {
        ob_end_clean();
    }

    return $response;
}

describe('a template engine from withTemplateEngine()', function (): void {
    test('renders template views with the context\'s data, and the block on updates only', function (): void {
        $engine = new RecordingEngine();
        $via = createVia((new Config())->withTemplateEngine($engine)->withBasePath('/app'));
        $ctx = new Context('/p_/e1', '/p', $via);
        $count = $ctx->signal(1, 'count');
        $save = $ctx->action(fn () => null, 'save-all');
        $ctx->view('page.tpl', fn (): array => ['title' => 'T', 'contextId' => 'mine'], block: 'main');

        expect($ctx->renderView())->toBe('page.tpl')
            ->and($ctx->renderView(isUpdate: true))->toBe('page.tpl[main]')
        ;

        $data = $engine->calls[1]['data'];
        expect($engine->calls[0]['block'])->toBeNull()
            ->and($engine->calls[1]['block'])->toBe('main')
            ->and($data['count'])->toBe($count)
            ->and($data['saveAll'])->toBe($save)
            ->and($data['_via'])->toBe(['signals' => ['count'], 'actions' => ['saveAll']])
            ->and($data['title'])->toBe('T')
            ->and($data['contextId'])->toBe('mine')
            ->and($data['currentRoute'])->toBe('/p')
            ->and($data['basePath'])->toBe('/app/')
            ->and($data['via_head'])->toBeInstanceOf(Stringable::class)
            ->and((string) $data['via_head'])->toBe($ctx->viaHead())
            ->and((string) $data['via_foot'])->toBe($ctx->viaFoot())
        ;
    });

    test('render() goes through it with an explicit block', function (): void {
        $engine = new RecordingEngine();
        $ctx = new Context('/p_/e2', '/p', createVia((new Config())->withTemplateEngine($engine)));

        expect($ctx->render('part.tpl'))->toBe('part.tpl')
            ->and($ctx->render('part.tpl', ['x' => 1], 'b'))->toBe('part.tpl[b]')
            ->and($engine->calls[1]['data']['x'])->toBe(1)
        ;
    });

    test('block: with an engine that renders no blocks throws, in view() and in render()', function (): void {
        $ctx = new Context('/p_/e3', '/p', createVia((new Config())->withTemplateEngine(new RecordingEngine(blocks: false))));

        expect(fn () => $ctx->view('page.tpl', block: 'main'))->toThrow(LogicException::class, "view('page.tpl') with block: 'main' needs a template engine that renders single blocks, and RecordingEngine does not")
            ->and(fn () => $ctx->render('page.tpl', [], 'main'))->toThrow(LogicException::class, "render('page.tpl') with block: 'main'")
            ->and($ctx->render('page.tpl'))->toBe('page.tpl')
        ;
    });

    test('getTwig() throws for an engine that is not a TwigEngine', function (): void {
        $via = createVia((new Config())->withTemplateEngine(new RecordingEngine()));

        expect(fn () => $via->getTwig())->toThrow(LogicException::class, 'but this app renders templates with RecordingEngine');
    });

    test('cannot be combined with withTemplateDir() or withTwigCacheDir()', function (string $call): void {
        $config = (new Config())->withTemplateEngine(new RecordingEngine());
        $config->{$call}(sys_get_temp_dir());

        expect(fn () => new Via($config))->toThrow(LogicException::class, 'withTemplateEngine() replaces withTemplateDir() and withTwigCacheDir()');
    })->with(['withTemplateDir', 'withTwigCacheDir']);
});

describe('without a template engine', function (): void {
    test('view() with a template name throws when it is called, naming composer require twig/twig and withTemplateEngine()', function (): void {
        $ctx = new Context('/p_/n1', '/p', createVia());

        expect(fn () => $ctx->view('page.html.twig'))->toThrow(
            LogicException::class,
            "view('page.html.twig') renders a template, and this app has no template engine. For Twig templates run composer require twig/twig, then set \$config->withTemplateDir(__DIR__ . '/templates') or ->withTemplateEngine(new \\Mbolli\\PhpVia\\Twig\\TwigEngine(__DIR__ . '/templates')).",
        )
            ->and($ctx->hasView())->toBeFalse()
        ;
    });

    test('render() throws the same way', function (): void {
        $ctx = new Context('/p_/n2', '/p', createVia());

        expect(fn () => $ctx->render('part.html.twig'))->toThrow(LogicException::class, "render('part.html.twig') renders a template, and this app has no template engine");
    });

    test('a page whose handler declares a template view answers 500 with the message in dev mode', function (): void {
        $via = createVia((new Config())->withDevMode(true));
        $via->page('/tpl', fn (Context $c) => $c->view('page.html.twig'));

        $response = templatePageResponse($via, '/tpl');

        expect($response->statusCode)->toBe(500)
            ->and($response->body)->toContain('composer require twig/twig')
        ;
    });

    test('closure views render', function (): void {
        $ctx = new Context('/p_/n3', '/p', createVia());
        $ctx->view(fn (): string => '<p>closure</p>');

        expect($ctx->renderView())->toBe('<p>closure</p>');
    });

    test('getTwig() throws, naming composer require twig/twig', function (): void {
        expect(fn () => createVia()->getTwig())->toThrow(LogicException::class, 'getTwig() needs Twig templates, and this app has no template engine. Run composer require twig/twig');
    });
});

describe('withTemplateDir()', function (): void {
    test('sets up a TwigEngine with the cache directory from withTwigCacheDir(), whose environment getTwig() returns', function (): void {
        $cache = sys_get_temp_dir() . '/via-twig-cache-' . bin2hex(random_bytes(4));
        $config = (new Config())->withTwigCacheDir($cache)->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')->withBasePath('/base');
        $via = createVia($config);
        $engine = $config->freeze()->templateEngine;

        expect($engine)->toBeInstanceOf(TwigEngine::class)
            ->and($via->getTwig())->toBe($engine->environment())
            ->and($via->getTwig()->getCache())->toBe($cache)
            ->and($via->getTwig()->isStrictVariables())->toBeTrue()
            ->and($via->getTwig()->getGlobals()['basePath'])->toBe('/base/')
        ;
    });

    test('a directory that does not exist fails at new Via()', function (): void {
        expect(fn () => createVia((new Config())->withTemplateDir('/nonexistent/via-templates')))->toThrow(LoaderError::class);
    });
});

describe('removed', function (): void {
    test('Context::renderString() throws and names getTwig()->createTemplate()', function (): void {
        $ctx = new Context('/p_/r1', '/p', createVia());

        expect(fn () => $ctx->renderString('{{ x }}', ['x' => 1]))->toThrow(BadMethodCallException::class, 'Context::renderString() was removed in php-via 0.14. Render a template held as a string with $app->getTwig()->createTemplate($template)->render($data).');
    });

    test('Context refers to no class of the Twig library', function (): void {
        $names = array_filter(
            token_get_all((string) file_get_contents(dirname(__DIR__, 2) . '/src/Context.php')),
            fn (array|string $token): bool => is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true),
        );

        expect(array_filter($names, fn (array $token): bool => str_starts_with(ltrim($token[1], '\\'), 'Twig\\')))->toBe([])
            ->and($names)->not->toBeEmpty()
        ;
    });
});
