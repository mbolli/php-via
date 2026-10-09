<?php

declare(strict_types=1);

/*
 * Sampling profiler for a running php-via app, one Excimer profiler per worker. See README.md.
 *
 * Include it after `$app = new Via(...)` and before `$app->start()` when VIA_PROFILE=1:
 *
 *   if (getenv('VIA_PROFILE') === '1') { require __DIR__ . '/../bench/profile/excimer.php'; }
 *
 * Control: write a command to $VIA_PROFILE_DIR/ctl and send SIGUSR2 to the worker.
 *   reset       drop the samples taken so far
 *   dump NAME   write the samples since the last reset or dump to NAME.w<id>.stacks
 * The worker answers by writing the command to ack.w<id>. On worker stop it dumps "final".
 *
 * A .stacks line is "count<TAB>leaf file:line<TAB>root;...;leaf". bench/profile/fold.php turns it
 * into collapsed stacks with the internal call on the leaf line added as a frame.
 */

use Mbolli\PhpVia\Via;
use OpenSwoole\Process;

/** @var Via $app */
(static function (Via $app): void {
    if (!extension_loaded('excimer')) {
        fwrite(STDERR, "VIA_PROFILE=1 but ext-excimer is not loaded; not profiling\n");

        return;
    }
    $dir = getenv('VIA_PROFILE_DIR') ?: sys_get_temp_dir() . '/via-profile';
    $period = (float) (getenv('VIA_PROFILE_PERIOD') ?: 0.001);
    $clock = getenv('VIA_PROFILE_CLOCK') === 'real' ? EXCIMER_REAL : EXCIMER_CPU;
    $root = dirname(__DIR__, 2) . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0o777, true);
    }

    $state = new class {
        public ?ExcimerProfiler $profiler = null;
        public int $workerId = 0;
    };

    $name = static function (array $f) use ($root): string {
        $fn = $f['function'] ?? '';
        if ($fn === '{closure}' || str_starts_with($fn, '{closure')) {
            $fn = '{closure:' . ($f['closure_line'] ?? '?') . '}';
            if (!isset($f['class'])) {
                $fn = '{closure:' . basename((string) ($f['file'] ?? '?')) . ':' . ($f['closure_line'] ?? '?') . '}';
            }
        }
        if (isset($f['class'])) {
            return $f['class'] . '::' . $fn;
        }

        return $fn !== '' ? $fn : '{main:' . str_replace($root, '', (string) ($f['file'] ?? '?')) . '}';
    };

    $dump = static function (string $label) use ($state, $dir, $root, $name): void {
        if ($state->profiler === null) {
            return;
        }
        $log = $state->profiler->flush();
        $agg = [];
        foreach ($log as $entry) {
            $trace = $entry->getTrace();
            $leaf = $trace[0] ?? [];
            $site = str_replace($root, '', (string) ($leaf['file'] ?? '-')) . ':' . ($leaf['line'] ?? 0);
            $frames = [];
            for ($i = count($trace) - 1; $i >= 0; --$i) {
                $frames[] = str_replace([';', "\t", "\n"], ['_', ' ', ' '], $name($trace[$i]));
            }
            $key = $site . "\t" . implode(';', $frames);
            $agg[$key] = ($agg[$key] ?? 0) + $entry->getEventCount();
        }
        $out = '';
        foreach ($agg as $key => $n) {
            $out .= $n . "\t" . $key . "\n";
        }
        file_put_contents("{$dir}/{$label}.w{$state->workerId}.stacks", $out);
    };

    $app->onWorkerStart(static function (int $workerId) use ($state, $dir, $period, $clock, $dump): void {
        $state->workerId = $workerId;
        $p = new ExcimerProfiler();
        $p->setPeriod($period);
        $p->setEventType($clock);
        $p->start();
        $state->profiler = $p;
        file_put_contents("{$dir}/pid.w{$workerId}", (string) getmypid());

        Process::signal(SIGUSR2, static function () use ($state, $dir, $dump): void {
            $cmd = trim((string) @file_get_contents("{$dir}/ctl"));
            if ($cmd === 'reset') {
                $state->profiler?->flush();
            } elseif (preg_match('/^dump ([\w.-]+)$/', $cmd, $m)) {
                $dump($m[1]);
            }
            file_put_contents("{$dir}/ack.w{$state->workerId}", $cmd);
        });
    });

    $app->onWorkerStop(static function () use ($state, $dump): void {
        $dump('final');
        $state->profiler?->stop();
        $state->profiler = null;
    });
})($app);
