<?php

declare(strict_types=1);

/*
 * Fold the profiler's .stacks files into collapsed stacks, sort every sample into a category,
 * and render flame graphs coloured by category. See README.md for the rules.
 *
 *   php fold.php out=DIR/NAME [twig=TWIGCACHE] [flamegraph=PATH/flamegraph.pl] [title=...]
 *                [meta=LOAD.json | secs=S ops=N opname=view] FILE.stacks...
 *
 * Writes NAME.folded (full stacks), NAME.cats.folded (category;class of the first matching frame),
 * NAME.shares.json, NAME.svg and NAME.cats.svg, and prints the category table.
 */

$o = [];
$files = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^(\w+)=(.*)$/s', $a, $m)) {
        $o[$m[1]] = $m[2];
    } else {
        $files[] = $a;
    }
}
if (!isset($o['out']) || $files === []) {
    fwrite(STDERR, "usage: php fold.php out=DIR/NAME [twig=DIR] [flamegraph=flamegraph.pl] [title=..] [secs=S ops=N opname=view] FILE.stacks...\n");

    exit(2);
}
$repo = dirname(__DIR__, 2) . '/';
$out = $o['out'];

/*
 * Categories, in the order they are tried on each frame. A sample belongs to the category of the
 * first frame, from the leaf towards the root, that matches a rule. Frames that match no rule
 * (generic internal functions such as preg_match() or array_map(), and root frames) pass the
 * sample on to their caller, so a str_replace() inside Twig counts as templating and the same call
 * inside PatchManager as framework time.
 *
 * Internal functions do not show up in Excimer's stacks: a sample that falls inside one is taken
 * when it returns, on the calling line. fold.php reads that line and adds the internal call it
 * finds there as a leaf frame, written with "()" (see internalCall()).
 */
$rules = [
    // [category, regex on the frame name, colour]
    ['compression', '/^brotli_\w+\(\)$/', '#d62728'],
    ['db', '/^(SQLite3|PDO)\w*::\w+\(\)$|^PhpVia\\\\Website\\\\Support\\\\Sqlite::|^Mbolli\\\\PhpVia\\\\State\\\\SqliteSnapshot::/', '#8c564b'],
    ['json', '/^json_(encode|decode)\(\)$/', '#e7ba52'],
    ['socket_write', '/^Response::(write|end|header|status|cookie|rawcookie|sendfile|isWritable)\(\)$|^Server::(send|push)\(\)$/', '#ff7f0e'],
    ['swoole_calls', '/^(Channel::\w+|Coroutine::\w+|Timer::\w+|Process::\w+|go|defer|usleep)\(\)$/', '#9edae5'],
    ['gc', '/^gc_collect_cycles\(\)$|^Mbolli\\\\PhpVia\\\\Support\\\\CycleCollector::/', '#7f7f7f'],
    ['highlighting', '/^(Tempest\\\\Highlight\\\\|Mbolli\\\\TempestHighlightDatastar\\\\|PhpVia\\\\Website\\\\Twig\\\\CodeRuntime::)/', '#17becf'],
    ['templating', '/^(Twig\\\\|twig:|__TwigTemplate_)/', '#2ca02c'],
    ['app', '/^PhpVia\\\\Website\\\\|^\{closure:(app|routes)\.php:/', '#9467bd'],
    ['fw_broadcast', '/^Mbolli\\\\PhpVia\\\\Via::(broadcast|flushBroadcasts|\w*[sS]ync\w*|\w*[fF]lush\w*|invalidate\w*|receiveBroadcast|markDirty|drainBroadcasts|publishPending|shouldCoalesce|isOwnPass|oweFlush|withoutThrottled|throttleWaitNs|collectSyncFailure|resyncOvertaken|warnIfSlowPass|\w*CallerMark\w*|callerMarksKey|hasFlushWork|msUntilNextTick)|^Mbolli\\\\PhpVia\\\\Context::(syncFanOut|broadcast)|^Mbolli\\\\PhpVia\\\\Broker\\\\|^Mbolli\\\\PhpVia\\\\State\\\\ScopeRegistry::/', '#08306b'],
    ['sse_format', '/^starfederation\\\\datastar\\\\/i', '#c49c94'],
    ['fw_sse', '/^Mbolli\\\\PhpVia\\\\Http\\\\(SseHandler|SseStream|SwooleSSEGenerator|Middleware\\\\BrotliStream)::/', '#2171b5'],
    ['fw_patch', '/^Mbolli\\\\PhpVia\\\\Context\\\\PatchManager::|^Mbolli\\\\PhpVia\\\\Context::(sync|syncSignals|syncWithoutViewTransition|getPatch|patchElements|execScript)$/', '#4292c6'],
    ['fw_render', '/^Mbolli\\\\PhpVia\\\\(Rendering|Twig)\\\\|^Mbolli\\\\PhpVia\\\\Context::(render\w*|view|viewKey|resolveViewData|documentData|buildAutoData|templateEngine|viaHead|viaFoot|beforeEachRender)|^Mbolli\\\\PhpVia\\\\Via::(buildHtmlDocument|decorateUpdate)/', '#6baed6'],
    ['fw_state', '/^Mbolli\\\\PhpVia\\\\(State\\\\|Signal::|Context\\\\SignalFactory::|Support\\\\(TypeCaster|ClientValue|SignalId)::|Composition\\\\)|^Mbolli\\\\PhpVia\\\\Context::(signal|getSignal\w*|injectSignals|collectTabSignals|tabState\w*|setTabState|localTabState)/', '#9ecae1'],
    ['fw_http', '/^Mbolli\\\\PhpVia\\\\(Http\\\\|Core\\\\Router|Core\\\\RequestSession|Core\\\\SessionManager)|^(Nyholm|Laminas|GuzzleHttp|Tuupola|Neomerx|Psr)\\\\/', '#3182bd'],
    ['fw_devbar', '/^Mbolli\\\\PhpVia\\\\(Tracing|DevBar)\\\\|^Mbolli\\\\PhpVia\\\\Context::(span|traceAttribute)$/', '#fdae6b'],
    ['fw_other', '/^Mbolli\\\\PhpVia\\\\/', '#c6dbef'],
];
$neutral = '#d9d9d9';

/*
 * Phases: what the worker was doing, from the frames anywhere in the stack (first match wins).
 * They are inclusive: a Twig render during a broadcast counts as broadcast fan-out.
 */
$phases = [
    'broadcast fan-out' => '/(^|;)Mbolli\\\\PhpVia\\\\(Via::(runFlush|doSyncContexts|syncContexts|flushBroadcasts|drainBroadcasts)|Context::syncFanOut)(;|$)/',
    'SSE stream loop' => '/(^|;)Mbolli\\\\PhpVia\\\\Http\\\\SseHandler::runStream(;|$)/',
    'SSE connect' => '/(^|;)Mbolli\\\\PhpVia\\\\Http\\\\SseHandler::handleSSE(;|$)/',
    'action request' => '/(^|;)Mbolli\\\\PhpVia\\\\Http\\\\ActionHandler::/',
    'page request' => '/(^|;)Mbolli\\\\PhpVia\\\\Http\\\\RequestHandler::(handlePage|doHandlePage)/',
    'other request' => '/(^|;)Mbolli\\\\PhpVia\\\\Http\\\\RequestHandler::handleRequest(;|$)/',
    'timers and cleanup' => '/Timer|ContextLifecycle|cleanup|Cleanup|CycleCollector|gc_collect_cycles|runGcCycle|endResetStreams|heartbeatStreams|^Mbolli\\\\PhpVia\\\\Via::\\{closure:\\d+\\};PhpVia\\\\Website\\\\/',
];
$labels = [
    'compression' => 'Brotli',
    'db' => 'SQLite',
    'json' => 'JSON',
    'socket_write' => 'socket writes',
    'swoole_calls' => 'OpenSwoole calls',
    'gc' => 'cycle GC',
    'highlighting' => 'highlighting',
    'templating' => 'Twig',
    'app' => 'website code',
    'sse_format' => 'Datastar SDK (event text)',
    'fw_broadcast' => 'via: broadcast',
    'fw_sse' => 'via: SSE loop',
    'fw_patch' => 'via: patches',
    'fw_render' => 'via: render',
    'fw_state' => 'via: signals/state',
    'fw_http' => 'via: HTTP + PSR',
    'fw_devbar' => 'via: Dev Bar tracing',
    'fw_other' => 'via: other',
    'loop' => 'event loop (no PHP frame)',
    'other' => 'other',
];
$colors = ['loop' => '#bcbd22', 'other' => '#f7b6d2'];
foreach ($rules as [$cat, , $col]) {
    $colors[$cat] = $col;
}

// Compiled Twig templates are classes named by hash; give them their template names back.
$twigNames = [];
if (isset($o['twig']) && is_dir($o['twig'])) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($o['twig'], FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $src = (string) file_get_contents((string) $f);
        if (preg_match('/class (__TwigTemplate_\w+)/', $src, $c) && preg_match('/function getTemplateName\(\)[^{]*\{\s*return "([^"]+)"/', $src, $t)) {
            $twigNames[$c[1]] = $t[1];
        }
    }
}

/** @var array<string, list<string>> $lineCache */
$lineCache = [];
$srcLine = static function (string $file, int $line) use (&$lineCache, $repo): string {
    $path = str_starts_with($file, '/') ? $file : $repo . $file;
    $lineCache[$path] ??= is_file($path) ? (file($path) ?: []) : [];

    return $lineCache[$path][$line - 1] ?? '';
};
$fileText = [];
$fileMentions = static function (string $file, string $re) use (&$fileText, $repo): bool {
    $path = str_starts_with($file, '/') ? $file : $repo . $file;
    $fileText[$path] ??= is_file($path) ? (string) file_get_contents($path) : '';

    return (bool) preg_match($re, $fileText[$path]);
};

$keywords = array_flip(['if', 'elseif', 'foreach', 'for', 'while', 'switch', 'match', 'array', 'isset', 'empty', 'unset', 'list', 'fn', 'function', 'return', 'echo', 'print', 'new', 'catch', 'exit', 'die', 'include', 'require', 'require_once', 'include_once', 'static', 'self', 'parent', 'and', 'or', 'not', 'use', 'declare', 'eval']);
// Rank of the internal calls worth naming when a line holds several.
$weight = static function (string $label): int {
    return match (true) {
        str_starts_with($label, 'brotli_') => 9,
        str_starts_with($label, 'SQLite3') || str_starts_with($label, 'PDO') => 8,
        str_starts_with($label, 'Response::') || str_starts_with($label, 'Server::') => 7,
        str_starts_with($label, 'json_') => 6,
        str_starts_with($label, 'gc_') => 6,
        str_starts_with($label, 'Channel::') || str_contains($label, '::') => 3,
        default => 1,
    };
};

/** The internal call on a source line, as a frame name, or null. */
$internalCall = static function (string $file, int $line) use ($srcLine, $fileMentions, $keywords, $weight): ?string {
    $best = null;
    $scan = static function (string $code) use ($file, $fileMentions, $keywords, $weight, &$best): void {
        $code = (string) preg_replace('/\/\/.*$|#.*$/', '', $code);
        if (preg_match_all('/(?<![\w\\\\$>:])\\\\?([a-zA-Z_]\w*)\s*\(/', $code, $m)) {
            foreach ($m[1] as $fn) {
                $lc = strtolower($fn);
                if (isset($keywords[$lc]) || !function_exists($fn) || !(new ReflectionFunction($fn))->isInternal()) {
                    continue;
                }
                $label = $fn . '()';
                if ($best === null || $weight($label) > $weight($best)) {
                    $best = $label;
                }
            }
        }
        if (preg_match_all('/(->|::)\s*(\w+)\s*\(/', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $op, $meth]) {
                $label = null;
                if ($op === '->' && in_array($meth, ['prepare', 'execute', 'exec', 'query', 'querySingle', 'fetchArray', 'bindValue', 'bindParam', 'lastInsertRowID', 'changes', 'reset', 'busyTimeout'], true) && $fileMentions($file, '/SQLite3|\bPDO\b/')) {
                    $label = 'SQLite3::' . $meth . '()';
                } elseif ($op === '->' && in_array($meth, ['write', 'end', 'header', 'status', 'cookie', 'rawcookie', 'sendfile', 'isWritable'], true) && $fileMentions($file, '/OpenSwoole\\\\Http\\\\Response|Swoole\\\\Http\\\\Response|\bResponse \$/')) {
                    $label = 'Response::' . $meth . '()';
                } elseif ($op === '->' && in_array($meth, ['send', 'push'], true) && $fileMentions($file, '/OpenSwoole\\\\(Http\\\\)?Server\b/') && !$fileMentions($file, '/Channel/')) {
                    $label = 'Server::' . $meth . '()';
                } elseif ($op === '->' && in_array($meth, ['push', 'pop', 'close', 'isEmpty', 'length', 'stats'], true) && $fileMentions($file, '/Coroutine\\\\Channel|new Channel/')) {
                    $label = 'Channel::' . $meth . '()';
                } elseif ($op === '::' && preg_match('/(?:Coroutine|Co|Timer|Process)::\s*' . $meth . '\s*\(/', $code, $cm)) {
                    $cls = str_contains($cm[0], 'Timer') ? 'Timer' : (str_contains($cm[0], 'Process') ? 'Process' : 'Coroutine');
                    $label = $cls . '::' . $meth . '()';
                }
                if ($label !== null && ($best === null || $weight($label) > $weight($best))) {
                    $best = $label;
                }
            }
        }
    };
    $scan($srcLine($file, $line));
    if ($best === null) {
        // The call may start on an earlier line of a multi-line statement.
        for ($k = 1; $k <= 3 && $best === null; ++$k) {
            $prev = $srcLine($file, $line - $k);
            if (preg_match('/[;{}]\s*$/', $prev)) {
                break;
            }
            $scan($prev);
        }
    }

    return $best;
};

$frameName = static function (string $f) use ($twigNames, $repo): string {
    // Anonymous classes: "Iface@anonymous\0/path/File.php:761$16::method"
    if (str_contains($f, "\0")) {
        $f = (string) preg_replace('/\x00(.*?):(\d+)\$\w+/', '{$1:$2}', $f);
        $f = str_replace($repo, '', $f);
    }
    if (preg_match('/^(__TwigTemplate_\w+)::(.*)$/', $f, $m)) {
        return 'twig:' . ($twigNames[$m[1]] ?? $m[1]) . '::' . $m[2];
    }

    return $f;
};

$categoryOf = static function (string $frame) use ($rules): ?string {
    foreach ($rules as [$cat, $re]) {
        if (preg_match($re, $frame)) {
            return $cat;
        }
    }

    return null;
};

// ── read and fold ───────────────────────────────────────────────────────────
$folded = [];
$catFolded = [];
$shares = [];
$phaseShares = [];
$total = 0;
$catOfName = [];
$internalCache = [];
foreach ($files as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $row) {
        [$n, $site, $stack] = explode("\t", $row, 3) + [2 => ''];
        $n = (int) $n;
        $frames = array_map($frameName, explode(';', $stack));
        if (preg_match('/^(.*):(\d+)$/', $site, $sm) && $sm[1] !== '-') {
            $internalCache[$site] ??= $internalCall($sm[1], (int) $sm[2]) ?? '';
            if ($internalCache[$site] !== '') {
                $frames[] = $internalCache[$site];
            }
        }
        $cat = null;
        $hit = null;
        // A stack of a single frame is a request or coroutine root that has run no PHP yet: the
        // C work OpenSwoole did before calling into PHP is counted when the next PHP code runs.
        if (count($frames) === 1) {
            $cat = 'loop';
            $hit = $frames[0];
        }
        for ($i = $cat === null ? count($frames) - 1 : -1; $i >= 0; --$i) {
            $catOfName[$frames[$i]] ??= $categoryOf($frames[$i]) ?? '';
            if ($catOfName[$frames[$i]] !== '') {
                $cat = $catOfName[$frames[$i]];
                $hit = $frames[$i];

                break;
            }
        }
        if ($cat === null) {
            $cat = 'other';
            $hit = end($frames) ?: '?';
        }
        $key = implode(';', $frames);
        $phase = 'other';
        foreach ($phases as $ph => $re) {
            if (preg_match($re, $key)) {
                $phase = $ph;

                break;
            }
        }
        $phaseShares[$phase] = ($phaseShares[$phase] ?? 0) + $n;
        $folded[$key] = ($folded[$key] ?? 0) + $n;
        // The categories graph groups by class (or function), not by method.
        $ck = $labels[$cat] . ';' . (preg_match('/^(.+?)::[^:]+$/', $hit, $hm) && !str_ends_with($hit, '()') ? $hm[1] : $hit);
        $catFolded[$ck] = ($catFolded[$ck] ?? 0) + $n;
        $shares[$cat] = ($shares[$cat] ?? 0) + $n;
        $total += $n;
    }
}
arsort($shares);
arsort($phaseShares);

$write = static function (string $path, array $stacks): void {
    ksort($stacks);
    $s = '';
    foreach ($stacks as $k => $n) {
        $s .= "{$k} {$n}\n";
    }
    file_put_contents($path, $s);
};
$write("{$out}.folded", $folded);
$write("{$out}.cats.folded", $catFolded);

// meta=FILE: the JSON line load.php printed for this window (secs, ops, server CPU per process).
$meta = isset($o['meta']) ? (array) json_decode((string) file_get_contents($o['meta']), true) : [];
foreach (['secs', 'ops', 'opname'] as $k) {
    if (!isset($o[$k]) && isset($meta[$k])) {
        $o[$k] = (string) $meta[$k];
    }
}
$secs = isset($o['secs']) ? (float) $o['secs'] : null;
$ops = isset($o['ops']) ? (int) $o['ops'] : null;
$period = (float) ($o['period'] ?? 0.001);
file_put_contents("{$out}.shares.json", json_encode([
    'samples' => $total,
    'period' => $period,
    'secs' => $secs,
    'ops' => $ops,
    'opname' => $o['opname'] ?? null,
    'meta' => $meta,
    'shares' => $shares,
    'phases' => $phaseShares,
], JSON_PRETTY_PRINT) . "\n");

printf("%-28s %8s %7s\n", 'category', 'samples', 'share');
foreach ($shares as $cat => $n) {
    printf("%-28s %8d %6.1f%%\n", $labels[$cat], $n, $n / max(1, $total) * 100);
}
foreach ($phaseShares as $ph => $n) {
    printf("  phase %-22s %6.1f%%\n", $ph, $n / max(1, $total) * 100);
}
printf('%-28s %8d   (%.0f ms CPU', 'total', $total, $total * $period * 1000);
if ($secs) {
    printf(', %.0f%% of a core', $total * $period / $secs * 100);
}
if ($ops) {
    printf(', %.3f ms per %s', $total * $period * 1000 / $ops, $o['opname'] ?? 'op');
}
echo ")\n";

// ── render ──────────────────────────────────────────────────────────────────
$fg = $o['flamegraph'] ?? null;
if ($fg === null || !is_file($fg)) {
    exit(0);
}
$tmp = sys_get_temp_dir() . '/fold-' . getmypid();
@mkdir($tmp);

$rgb = static function (string $hex): string {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

    return "rgb({$r},{$g},{$b})";
};
$legend = static function (string $svg) use ($shares, $labels, $colors, $total): string {
    // flamegraph.pl leaves a line for the subtitle; draw the legend there instead.
    if (!preg_match('/<text id="subtitle"[^>]*y="([\d.]+)"[^>]*>.*?<\/text>/s', $svg, $m)) {
        return $svg;
    }
    $y = (float) $m[1];
    $x = 10.0;
    $g = '<g id="legend" font-family="Verdana" font-size="12">';
    // Categories under 1% share one entry, so the legend fits one line.
    $items = [];
    $rest = 0;
    foreach ($shares as $cat => $n) {
        if ($n / max(1, $total) >= 0.01) {
            $items[] = [sprintf('%s %.1f%%', $labels[$cat], $n / max(1, $total) * 100), $colors[$cat]];
        } else {
            $rest += $n;
        }
    }
    if ($rest > 0) {
        $items[] = [sprintf('rest %.1f%%', $rest / max(1, $total) * 100), '#ffffff'];
    }
    foreach ($items as [$text, $color]) {
        $g .= sprintf('<rect x="%.1f" y="%.1f" width="12" height="12" fill="%s" stroke="#555" stroke-width="0.5"/>', $x, $y - 10, $color);
        $g .= sprintf('<text x="%.1f" y="%.1f">%s</text>', $x + 16, $y, htmlspecialchars($text));
        $x += 16 + strlen($text) * 6.8 + 12;
    }

    return str_replace($m[0], $g . '</g>', $svg);
};

$render = static function (string $foldedPath, string $svgPath, array $palette, string $title, string $countname) use ($fg, $tmp, $legend, $o): void {
    $map = '';
    foreach ($palette as $name => $col) {
        $map .= "{$name}->{$col}\n";
    }
    file_put_contents("{$tmp}/palette.map", $map);
    $cmd = sprintf(
        'cd %s && perl %s --cp --hash --width %d --minwidth 0.5 --fontsize 12 --title %s --subtitle legend --countname %s %s',
        escapeshellarg($tmp),
        escapeshellarg(realpath($fg)),
        (int) ($o['width'] ?? 1800),
        escapeshellarg($title),
        escapeshellarg($countname),
        escapeshellarg(realpath($foldedPath))
    );
    $svg = (string) shell_exec($cmd);
    file_put_contents($svgPath, $legend($svg));
};

$palette = [];
foreach (array_keys($folded) as $k) {
    foreach (explode(';', $k) as $f) {
        if (!isset($palette[$f])) {
            $c = $catOfName[$f] ?? ($categoryOf($f) ?? '');
            $palette[$f] = $rgb($c !== '' ? $colors[$c] : $neutral);
        }
    }
}
$title = $o['title'] ?? basename($out);
$render("{$out}.folded", "{$out}.svg", $palette, $title, $o['countname'] ?? 'ms CPU');

$catPalette = [];
foreach (array_keys($catFolded) as $k) {
    [$c, $f] = explode(';', $k, 2);
    $cat = array_search($c, $labels, true);
    $catPalette[$c] = $rgb($colors[$cat]);
    $catPalette[$f] = $rgb($colors[$cat]);
}
$render("{$out}.cats.folded", "{$out}.cats.svg", $catPalette, $title . ': categories', $o['countname'] ?? 'ms CPU');

array_map('unlink', glob("{$tmp}/*") ?: []);
rmdir($tmp);
