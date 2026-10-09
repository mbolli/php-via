<?php

declare(strict_types=1);

/*
 * Cost of formatting one patchElements() event in starfederation/datastar-php.
 * Needs only a copy of the SDK's src/ directory; no Composer install.
 *
 *   php bench.php sdk=DIR mode=time    size=30|300|2000 secs=S      prints µs per event
 *   php bench.php sdk=DIR mode=profile size=300 secs=S period=0.0001 out=FILE.folded
 *   php bench.php sdk=DIR mode=emit                                  prints the outputs (serialized)
 *
 * mode=time and mode=profile first compare this SDK's output with sdk-stock's (mode=emit run in a
 * child process) for every payload and option set below, and stop if one byte differs.
 */

$o = ['sdk' => __DIR__ . '/sdk-stock', 'mode' => 'time', 'size' => '300', 'secs' => '3', 'period' => '0.0001'];
foreach (array_slice($argv, 1) as $a) {
    [$k, $v] = explode('=', $a, 2);
    $o[$k] = $v;
}
$sdk = realpath($o['sdk']) ?: throw new RuntimeException("no SDK at {$o['sdk']}");

spl_autoload_register(static function (string $class) use ($sdk): void {
    $prefix = 'starfederation\\datastar\\';
    if (str_starts_with($class, $prefix)) {
        require $sdk . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

use starfederation\datastar\events\EventInterface;
use starfederation\datastar\ServerSentEventGenerator;

/** Returns the event text instead of echoing it, as an app that writes the stream itself does. */
final class StringGenerator extends ServerSentEventGenerator
{
    protected function sendEvent(EventInterface $event): string
    {
        return $event->getOutput();
    }
}

/** A chat message list as a template engine renders it: 7 lines per message, 2 for the wrapper. */
function chatList(int $messages): string
{
    $users = ['alice', 'bob', 'carol', 'dave', 'erin'];
    $html = "<div id=\"messages\" class=\"chat-messages\">\n";
    for ($i = 1; $i <= $messages; ++$i) {
        $html .= "    <div class=\"message\" id=\"msg-{$i}\">\n"
            . "        <div class=\"message-meta\">\n"
            . '            <span class="username">' . $users[$i % 5] . "</span>\n"
            . sprintf("            <span class=\"timestamp\">12:%02d:%02d</span>\n", intdiv($i, 60) % 60, $i % 60)
            . "        </div>\n"
            . "        <p class=\"text\">Message {$i}: did the build on main pass before the release? &#39;yes&#39;</p>\n"
            . "    </div>\n";
    }

    return $html . "</div>\n";
}

/** Payload sizes in lines => messages in the list. */
const SIZES = ['30' => 4, '300' => 43, '2000' => 285];

function payload(string $size): string
{
    $n = SIZES[$size] ?? throw new InvalidArgumentException("size must be one of " . implode(', ', array_keys(SIZES)));

    return chatList($n);
}

/** Every event type and option the SDK writes, on the three payloads and some edge cases. */
function emitAll(): array
{
    $gen = new StringGenerator();
    $optionSets = [
        [],
        ['selector' => '#messages', 'mode' => 'inner'],
        ['selector' => '0'],
        ['selector' => '#x', 'mode' => 'append', 'namespace' => 'svg', 'useViewTransition' => true,
            'viewTransitionSelector' => '#vt', 'eventId' => 'ev-1', 'retryDuration' => 5000],
        ['eventId' => '42', 'retryDuration' => 1000],
    ];
    $bodies = array_map('payload', array_keys(SIZES));
    $bodies = array_merge($bodies, ['', ' ', "\n", "\n\n", "  <p>a</p>  ", "<p>a</p>\n\n<p>b</p>\n", "\n<p>a</p>\n\t",
        "<p>a\r\nb</p>", "<p>a\rb</p>", "x\n", 'single line']);
    $out = [];
    foreach ($bodies as $b) {
        foreach ($optionSets as $opts) {
            $out[] = $gen->patchElements($b, $opts);
        }
        $out[] = $gen->patchSignals($b, ['onlyIfMissing' => true]);
        $out[] = $gen->executeScript($b, ['autoRemove' => false, 'attributes' => ['type' => 'module']]);
        $out[] = $gen->executeScript($b);
    }
    $out[] = $gen->patchSignals(['a' => 1, 'b' => ['c' => "x\ny"]]);
    $out[] = $gen->removeElements('#gone', ['eventId' => '7']);
    $out[] = $gen->location('/next');

    return $out;
}

function assertSameAsStock(string $sdk): int
{
    $mine = emitAll();
    $stockDir = __DIR__ . '/sdk-stock';
    if ($sdk === realpath($stockDir)) {
        return count($mine);
    }
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' mode=emit sdk=' . escapeshellarg($stockDir);
    $stock = unserialize((string) shell_exec($cmd));
    if (!is_array($stock) || count($stock) !== count($mine)) {
        fwrite(STDERR, "could not read the stock SDK's output\n");
        exit(1);
    }
    foreach ($stock as $i => $s) {
        if ($s !== $mine[$i]) {
            fwrite(STDERR, "output #{$i} differs from the stock SDK:\n" . var_export($s, true) . "\n" . var_export($mine[$i], true) . "\n");
            exit(1);
        }
    }

    return count($mine);
}

function cpuSeconds(): float
{
    $r = getrusage();

    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
}

/** The measured loop: format the same event until $secs have passed. Returns the event count. */
function run(StringGenerator $gen, string $html, float $secs): int
{
    $n = 0;
    $end = hrtime(true) + (int) ($secs * 1e9);
    do {
        for ($i = 0; $i < 64; ++$i) {
            $gen->patchElements($html);
        }
        $n += 64;
    } while (hrtime(true) < $end);

    return $n;
}

if ($o['mode'] === 'emit') {
    echo serialize(emitAll());
    exit(0);
}

$checked = assertSameAsStock($sdk);
$html = payload($o['size']);
$lines = substr_count(trim($html), "\n") + 1;
$gen = new StringGenerator();
$secs = (float) $o['secs'];
run($gen, $html, 0.3); // warm up

if ($o['mode'] === 'time') {
    $c0 = cpuSeconds();
    $t0 = hrtime(true);
    $n = run($gen, $html, $secs);
    $wall = (hrtime(true) - $t0) / 1e9;
    $cpu = cpuSeconds() - $c0;
    printf("%s lines=%d bytes=%d events=%d cpu_us_per_event=%.3f wall_us_per_event=%.3f identical_outputs=%d\n",
        basename($sdk), $lines, strlen($html), $n, $cpu / $n * 1e6, $wall / $n * 1e6, $checked);
    exit(0);
}

if ($o['mode'] === 'profile') {
    $p = new ExcimerProfiler();
    $p->setEventType(EXCIMER_CPU);
    $p->setPeriod((float) $o['period']);
    $c0 = cpuSeconds();
    $p->start();
    $n = run($gen, $html, $secs);
    $p->stop();
    $cpu = cpuSeconds() - $c0;
    $log = $p->getLog();

    // Excimer has no frames for internal functions: a sample taken inside explode() or
    // str_replace() is counted on the calling line once the call returns. Add that line's file,
    // number and code as a leaf frame, so the internal calls on it are visible.
    $src = [];
    $leafLine = static function (string $file, int $line) use (&$src): string {
        $src[$file] ??= file($file) ?: [];
        $code = trim($src[$file][$line - 1] ?? '');

        return basename($file) . ':' . $line . '  ' . (strlen($code) > 90 ? substr($code, 0, 87) . '...' : $code);
    };
    $agg = [];
    $samples = 0;
    foreach ($log as $entry) {
        $trace = $entry->getTrace();
        $frames = [];
        for ($i = count($trace) - 1; $i >= 0; --$i) {
            $f = $trace[$i];
            $name = isset($f['class']) ? $f['class'] . '::' . $f['function'] : ($f['function'] ?? '{main}');
            $frames[] = str_replace(['starfederation\\datastar\\', ';'], ['', '_'], $name);
        }
        if (isset($trace[0]['file'], $trace[0]['line'])) {
            $frames[] = str_replace(';', '', $leafLine($trace[0]['file'], (int) $trace[0]['line']));
        }
        $key = implode(';', $frames);
        $agg[$key] = ($agg[$key] ?? 0) + $entry->getEventCount();
        $samples += $entry->getEventCount();
    }
    ksort($agg);
    $txt = '';
    foreach ($agg as $k => $c) {
        $txt .= "{$k} {$c}\n";
    }
    file_put_contents($o['out'], $txt);
    printf("%s lines=%d events=%d cpu_s=%.3f samples=%d sample_ms=%.3f cpu_us_per_event=%.3f identical_outputs=%d\n",
        basename($sdk), $lines, $n, $cpu, $samples, $samples * (float) $o['period'] * 1000, $cpu / $n * 1e6, $checked);
}
