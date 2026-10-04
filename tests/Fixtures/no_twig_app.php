<?php

declare(strict_types=1);

/*
 * Fixture for NoTwigTest: php-via as an app sees it when twig/twig is not installed. The autoloader
 * below is Composer's class map without the Twig\ classes and its files without Twig's.
 *
 * Prints one "key=value" per line.
 */

$vendor = dirname(__DIR__, 2) . '/vendor';
$classMap = require $vendor . '/composer/autoload_classmap.php';
spl_autoload_register(static function (string $class) use ($classMap): void {
    if (!str_starts_with($class, 'Twig\\') && isset($classMap[$class])) {
        require $classMap[$class];
    }
});
foreach (require $vendor . '/composer/autoload_files.php' as $file) {
    if (!str_contains($file, '/twig/twig/')) {
        require_once $file;
    }
}

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\TemplateEngine;
use Mbolli\PhpVia\Via;

function report(string $key, int|string $value): void {
    echo $key . '=' . str_replace("\n", ' ', (string) $value) . "\n";
}

/**
 * The exception $fn throws, as "Class: message", or 'none'.
 */
function thrown(callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e::class . ': ' . $e->getMessage();
    }

    return 'none';
}

report('twig_loadable', class_exists('Twig\Environment') ? 1 : 0);

$via = new Via((new Config())->withLogLevel('error'));
$page = new Context('/_/notwig', '/', $via);
$count = $page->signal(1, 'count');
$increment = $page->action(fn () => $count->increment(), 'increment');
$page->view(fn (bool $isUpdate): string => '<p id="count">' . $count->int() . '</p>' . ($isUpdate ? '' : "<button data-on:click=\"@post('{$increment->url()}')\">+</button>"));

$html = $via->buildHtmlDocument($page);
report('page_has_via_head', str_contains($html, $page->viaHead()) ? 1 : 0);
report('page_has_via_foot', str_contains($html, $page->viaFoot()) ? 1 : 0);
report('page_has_content', str_contains($html, '<p id="count">1</p>') ? 1 : 0);

$count->increment();
report('update', $page->renderView(isUpdate: true));

$tpl = new Context('/_/notwig-tpl', '/tpl', $via);
report('template_view', thrown(fn () => $tpl->view('page.html.twig')));
report('get_twig', thrown(fn () => $via->getTwig()));
report('template_dir', thrown(fn () => new Via((new Config())->withTemplateDir(__DIR__))));

$engine = new class implements TemplateEngine {
    public function render(string $template, array $data, ?string $block = null): string {
        return "{$template}:{$data['count']->int()}:" . (str_contains((string) $data['via_head'], 'via_ctx') ? 'head' : 'nohead');
    }

    public function supportsBlocks(): bool {
        return false;
    }
};
$engineVia = new Via((new Config())->withLogLevel('error')->withTemplateEngine($engine));
$own = new Context('/_/notwig-own', '/own', $engineVia);
$own->signal(7, 'count');
$own->view('own.tpl');
report('own_engine', $own->renderView());

report('twig_classes_loaded', count(array_filter(get_declared_classes(), static fn (string $c): bool => str_starts_with($c, 'Twig\\'))));
report('done', 1);
