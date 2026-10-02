<?php

declare(strict_types=1);

// Single-core speed for the work a php-via page view does: string building and Brotli level 4.
// Run with the same php binary and ini as the server, e.g. php -d opcache.enable_cli=1 calibrate.php
$html = str_repeat("<div class=\"doc\"><p>php-via docs text with <code>\$c->signal()</code> 12345</p></div>\n", 1200);
$best = ['brotli' => INF, 'php' => INF];
for ($round = 0; $round < 5; ++$round) {
    $t = hrtime(true);
    for ($i = 0; $i < 200; ++$i) {
        brotli_compress($html, 4);
    }
    $best['brotli'] = min($best['brotli'], (hrtime(true) - $t) / 1e6);

    $t = hrtime(true);
    $out = '';
    for ($i = 0; $i < 400_000; ++$i) {
        $out .= htmlspecialchars('<b>' . $i . '</b>') . str_pad((string) ($i % 97), 3, '0', STR_PAD_LEFT);
        if (strlen($out) > 65536) {
            $out = '';
        }
    }
    $best['php'] = min($best['php'], (hrtime(true) - $t) / 1e6);
}
printf("brotli %.0f ms, php %.0f ms (lower is faster; compare with the reference machine)\n", $best['brotli'], $best['php']);
