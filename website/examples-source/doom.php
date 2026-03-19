<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Server-Side DOOM Streaming.
 *
 * Runs DOOM in a virtual framebuffer (Xvfb) and streams
 * frames to the browser via SSE as base64 data URLs.
 *
 * Prerequisites:
 *   sudo apt install xvfb dsda-doom scrot xdotool imagemagick
 */
$app = new Via(
    (new Config())
        ->withPort(3013)
        ->withDevMode(true)
);

$app->page('/doom', function (Context $c): void {
    $frameData = $c->signal('', name: 'frameData');
    $fps = $c->signal(0, name: 'fps');
    $frameCount = $c->signal(0, name: 'frameCount');
    $gameStatus = $c->signal('stopped', name: 'gameStatus');
    $errorMessage = $c->signal('', name: 'errorMessage');
    $frameSize = $c->signal(0, name: 'frameSize');
    $cachedFrames = $c->signal(0, name: 'cachedFrames');

    $startGame = $c->action(function (Context $c) use ($gameStatus, $errorMessage): void {
        if (isset($_SESSION['doom_game']) && $_SESSION['doom_game'] instanceof DoomGame) {
            $gameStatus->setValue('running');
            $c->syncSignals();

            return;
        }

        // Validate required system tools
        $requiredTools = ['Xvfb', 'dsda-doom', 'scrot', 'xdotool', 'convert'];
        $missing = [];
        foreach ($requiredTools as $tool) {
            $path = shell_exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null');
            if (empty(trim((string) $path))) {
                $missing[] = $tool;
            }
        }
        if ($missing !== []) {
            $errorMessage->setValue('Missing required tools: ' . implode(', ', $missing));
            $gameStatus->setValue('error');
            $c->syncSignals();

            return;
        }

        $game = new DoomGame(640, 480);
        if ($game->start()) {
            $_SESSION['doom_game'] = $game;
            $gameStatus->setValue('running');
            $errorMessage->setValue('');
        } else {
            $gameStatus->setValue('error');
            $errorMessage->setValue('Failed to start DOOM. Check server logs.');
        }
        $c->syncSignals();
    }, 'startGame');

    $stopGame = $c->action(function (Context $c) use ($gameStatus, $frameData): void {
        if (isset($_SESSION['doom_game']) && $_SESSION['doom_game'] instanceof DoomGame) {
            $_SESSION['doom_game']->stop();
            $_SESSION['doom_game'] = null;
            $gameStatus->setValue('stopped');
            $frameData->setValue('');
        }
        $c->syncSignals();
    }, 'stopGame');

    // Forward browser keyboard events to xdotool
    $keyDown = $c->action(function (Context $c): void {
        $key = $_GET['key'] ?? null;
        if (isset($_SESSION['doom_game']) && $_SESSION['doom_game'] instanceof DoomGame && is_string($key)) {
            $_SESSION['doom_game']->sendKeyDown($key);
        }
    }, 'keyDown');

    $keyUp = $c->action(function (Context $c): void {
        $key = $_GET['key'] ?? null;
        if (isset($_SESSION['doom_game']) && $_SESSION['doom_game'] instanceof DoomGame && is_string($key)) {
            $_SESSION['doom_game']->sendKeyUp($key);
        }
    }, 'keyUp');

    // Stream frames at ~10 FPS
    $c->interval(100, function () use ($c, $gameStatus, $frameData, $fps, $frameCount, $frameSize, $cachedFrames): void {
        $game = $_SESSION['doom_game'] ?? null;
        if (!$game instanceof DoomGame || $gameStatus->string() !== 'running') {
            return;
        }

        $frame = $game->captureFrame();
        if ($frame === null) {
            return;
        }

        $frameData->setValue($frame['data']);

        if (!($frame['cached'] ?? false)) {
            $frameCount->setValue($frameCount->int() + 1);
            if (isset($frame['size'])) {
                $frameSize->setValue(round($frame['size'] / 1024, 1));
            }
        } else {
            $cachedFrames->setValue($cachedFrames->int() + 1);
        }

        // Calculate FPS from non-cached frames
        /** @var null|float $lastFpsTime */
        static $lastFpsTime = null;

        /** @var int $framesSinceLastFps */
        static $framesSinceLastFps = 0;

        if (!($frame['cached'] ?? false)) {
            ++$framesSinceLastFps;
        }
        $now = microtime(true);

        if ($lastFpsTime === null) {
            $lastFpsTime = $now;
        } elseif ($now - $lastFpsTime >= 1.0) {
            $fps->setValue(round($framesSinceLastFps / ($now - $lastFpsTime), 1));
            $framesSinceLastFps = 0;
            $lastFpsTime = $now;
        }

        $c->syncSignals();
    });

    $c->view('doom.html.twig', [
        'frameData' => $frameData,
        'fps' => $fps,
        'frameCount' => $frameCount,
        'frameSize' => $frameSize,
        'cachedFrames' => $cachedFrames,
        'gameStatus' => $gameStatus,
        'errorMessage' => $errorMessage,
        'startGame' => $startGame,
        'stopGame' => $stopGame,
        'keyDown' => $keyDown,
        'keyUp' => $keyUp,
    ]);
});

$app->start();
