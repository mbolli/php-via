<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\SwooleSSEGenerator;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\enums\NamespaceType;
use starfederation\datastar\events\EventInterface;
use starfederation\datastar\ServerSentEventGenerator;

/*
 * SwooleSSEGenerator writes the event text itself. These compare it byte for byte with the SDK's
 * own output, so an SDK release that changes the format fails here.
 */

function sdkReference(): ServerSentEventGenerator {
    return new class extends ServerSentEventGenerator {
        protected function sendEvent(EventInterface $event): string {
            return $event->getOutput();
        }
    };
}

$payloads = [
    'empty' => '',
    'one line' => '<div id="a">x</div>',
    'many lines' => "<ul>\n  <li>one</li>\n  <li>two</li>\n\n  <li>four</li>\n</ul>",
    'trailing newline' => "<p>x</p>\n",
    'surrounding whitespace' => "  \n\t<p>x</p>\n\n  ",
    'only newlines' => "\n\n\n",
    'zero' => '0',
    'data-like text' => "<pre>\ndata: selector body\nevent: x\n</pre>",
    'unicode' => "<p>Grüezi</p>\n<p>日本</p>",
];

$elementOptions = [
    'none' => [],
    'selector' => ['selector' => '#list'],
    'selector zero' => ['selector' => '0'],
    'selector empty' => ['selector' => ''],
    'namespace svg' => ['namespace' => NamespaceType::Svg],
    'namespace mathml string' => ['namespace' => 'mathml'],
    'namespace html' => ['namespace' => NamespaceType::Html],
    'view transition' => ['useViewTransition' => true],
    'view transition off' => ['useViewTransition' => false, 'viewTransitionSelector' => '#x'],
    'view transition selector' => ['useViewTransition' => true, 'viewTransitionSelector' => '#board'],
    'event id' => ['eventId' => 'abc-1'],
    'event id zero' => ['eventId' => '0'],
    'retry' => ['retryDuration' => 2500],
    'retry default' => ['retryDuration' => 1000],
    'retry zero' => ['retryDuration' => 0],
    'everything' => [
        'selector' => '#a > .b',
        'mode' => ElementPatchMode::Inner,
        'namespace' => NamespaceType::Svg,
        'useViewTransition' => true,
        'viewTransitionSelector' => '#a',
        'eventId' => '42',
        'retryDuration' => 5000,
    ],
];
foreach (ElementPatchMode::cases() as $mode) {
    $elementOptions["mode {$mode->value}"] = ['mode' => $mode, 'selector' => '#m'];
    $elementOptions["mode {$mode->value} as string"] = ['mode' => $mode->value];
}

describe('SwooleSSEGenerator', function () use ($payloads, $elementOptions): void {
    it('writes patchElements() as the SDK does', function () use ($payloads, $elementOptions): void {
        $sse = new SwooleSSEGenerator();
        $sdk = sdkReference();
        foreach ($payloads as $payloadName => $html) {
            foreach ($elementOptions as $optionsName => $options) {
                expect($sse->patchElements($html, $options))->toBe($sdk->patchElements($html, $options), "{$payloadName}, {$optionsName}");
            }
        }
    });

    it('writes patchSignals() as the SDK does', function () use ($payloads): void {
        $sse = new SwooleSSEGenerator();
        $sdk = sdkReference();
        $signals = [
            'array' => ['count' => 1, 'name' => "a\nb", 'nested' => ['x' => [1, 2], 'y' => null]],
            'empty array' => [],
            'string' => "{count: 1,\n name: 'x'}\n",
        ] + $payloads;
        $options = [[], ['onlyIfMissing' => true], ['onlyIfMissing' => false], ['eventId' => '7', 'retryDuration' => 3000, 'onlyIfMissing' => true]];
        foreach ($signals as $name => $value) {
            foreach ($options as $i => $opts) {
                expect($sse->patchSignals($value, $opts))->toBe($sdk->patchSignals($value, $opts), "{$name}, options {$i}");
            }
        }
    });

    it('writes executeScript() as the SDK does', function () use ($payloads): void {
        $sse = new SwooleSSEGenerator();
        $sdk = sdkReference();
        $options = [
            [],
            ['autoRemove' => false],
            ['attributes' => ['type' => 'module', 'data-x' => '"q" & <t>']],
            ['attributes' => ['nonce' => 'n'], 'autoRemove' => false, 'eventId' => 'e', 'retryDuration' => 1500],
        ];
        foreach ($payloads + ['script' => "const a = 1;\nconsole.log(a);\n"] as $name => $script) {
            foreach ($options as $i => $opts) {
                expect($sse->executeScript($script, $opts))->toBe($sdk->executeScript($script, $opts), "{$name}, options {$i}");
            }
        }
    });

    it('turns CR and CRLF into LF before writing the lines', function (): void {
        $sse = new SwooleSSEGenerator();
        $sdk = sdkReference();
        $html = "<p>a</p>\r\n<p>b</p>\r<p>c</p>\r\n";
        $lf = "<p>a</p>\n<p>b</p>\n<p>c</p>\n";

        expect($sse->patchElements($html, ['mode' => ElementPatchMode::Append]))->toBe($sdk->patchElements($lf, ['mode' => ElementPatchMode::Append]))
            ->and($sse->patchSignals("{a: 1,\r\nb: 2}"))->toBe($sdk->patchSignals("{a: 1,\nb: 2}"))
            ->and($sse->executeScript("a()\r\rb()"))->toBe($sdk->executeScript("a()\n\nb()"))
        ;
    });

    it('replaces line breaks in selectors with spaces', function (): void {
        $sse = new SwooleSSEGenerator();
        $sdk = sdkReference();
        $options = ['selector' => "#a\r\nevent: x", 'useViewTransition' => true, 'viewTransitionSelector' => "#b\rdata: y"];

        expect($sse->patchElements('<b>x</b>', $options))
            ->toBe($sdk->patchElements('<b>x</b>', ['selector' => '#a  event: x', 'useViewTransition' => true, 'viewTransitionSelector' => '#b data: y']))
        ;
    });
});
