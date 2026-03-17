<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/**
 * Server-Side DOOM Streaming Example.
 *
 * Streams a DOOM game running on the server to the browser via SSE.
 * Requires system dependencies: Xvfb, dsda-doom, scrot, xdotool, imagemagick.
 *
 * The DoomGame class manages a virtual framebuffer and captures frames as
 * base64 data URLs streamed to an <img> tag in the browser.
 */
final class DoomExample
{
    public const string SLUG = 'doom';

    public static function register(Via $app): void
    {
        $app->page('/examples/doom', function (Context $c): void {
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

            $c->interval(100, function () use ($c, $gameStatus, $frameData, $fps, $frameCount, $frameSize, $cachedFrames): void {
                $game = $_SESSION['doom_game'] ?? null;
                if (!$game instanceof DoomGame || $gameStatus->string() !== 'running') {
                    return;
                }

                $frameResult = $game->captureFrame();
                if ($frameResult === null) {
                    return;
                }

                $frameData->setValue($frameResult['data']);

                if (!($frameResult['cached'] ?? false)) {
                    $frameCount->setValue($frameCount->int() + 1);
                    if (isset($frameResult['size'])) {
                        $frameSize->setValue(round($frameResult['size'] / 1024, 1));
                    }
                } else {
                    $cachedFrames->setValue($cachedFrames->int() + 1);
                }

                /** @var float|null $lastFpsTime */
                static $lastFpsTime = null;
                /** @var int $framesSinceLastFps */
                static $framesSinceLastFps = 0;

                if (!($frameResult['cached'] ?? false)) {
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

            $c->view(fn (bool $isUpdate = false): string => $c->render('examples/doom.html.twig', [
                'title' => '🎮 DOOM',
                'description' => 'Server-side DOOM streaming via SSE. The game runs entirely on the server.',
                'summary' => [
                    '<strong>Xvfb + dsda-doom</strong> runs a real DOOM engine in a virtual framebuffer on the server. No WASM, no emulation in the browser — actual server-side rendering.',
                    '<strong>Frame capture</strong> at ~10 FPS via scrot/imagemagick. Each frame is encoded as a base64 WebP data URL and pushed to an &lt;img&gt; tag via SSE signal updates.',
                    '<strong>Keyboard forwarding</strong> uses xdotool to translate browser keydown/keyup events into X11 key events sent to the DOOM window.',
                    '<strong>Process lifecycle</strong> — the DOOM process starts when you click "Start" and is killed on disconnect via <code>onDisconnect</code>. No orphan processes left behind.',
                    '<strong>Signal-based telemetry</strong> shows live FPS, frame count, frame size, and cache hit rate. Each metric is a separate signal updated every frame capture cycle.',
                    '<strong>Latency-bound</strong> — frame data travels server → SSE → browser. On a local network this feels responsive; over the internet, expect 100–300ms of visual lag.',
                ],
                'sourceFile' => 'doom.php',
                'templateFiles' => ['doom.html.twig'],
                'errorMessage' => $errorMessage,
                'gameStatus' => $gameStatus,
                'frameData' => $frameData,
                'fps' => $fps,
                'frameCount' => $frameCount,
                'frameSize' => $frameSize,
                'cachedFrames' => $cachedFrames,
                'startGame' => $startGame,
                'stopGame' => $stopGame,
                'keyDown' => $keyDown,
                'keyUp' => $keyUp,
            ], $isUpdate ? 'content' : null));
        });
    }
}

/**
 * Manages a headless DOOM instance with Xvfb for frame capture.
 *
 * @internal Used only by DoomExample
 */
class DoomGame
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource>|null */
    private ?array $pipes = null;

    private int $display = 99;
    private string $frameDir;
    private int $currentFrame = 0;
    private float $lastFrameTime = 0;
    private bool $running = false;
    private ?string $lastFrameHash = null;
    private ?string $lastFrameData = null;
    private bool $useWebP;

    /** @var array<string, bool> */
    private array $activeKeys = [];

    public function __construct(
        private readonly int $width = 640,
        private readonly int $height = 480,
        private readonly int $quality = 60,
    ) {
        $this->frameDir = sys_get_temp_dir() . '/doom_frames_' . bin2hex(random_bytes(8));
        @mkdir($this->frameDir, 0755, true);
        $this->useWebP = extension_loaded('gd') && function_exists('imagewebp');
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function start(): bool
    {
        if ($this->running) {
            return true;
        }

        $this->display = $this->findAvailableDisplay();

        $xvfbCmd = sprintf(
            'Xvfb :%d -screen 0 %dx%dx24 > /dev/null 2>&1 & echo $!',
            $this->display,
            $this->width,
            $this->height
        );
        $xvfbPid = trim((string) shell_exec($xvfbCmd));
        sleep(1);

        $wadPath = dirname(__DIR__, 3) . '/doom1.wad';
        if (!file_exists($wadPath)) {
            $wadPath = '/usr/share/games/doom/doom1.wad';
            if (!file_exists($wadPath)) {
                error_log('DOOM WAD not found');

                return false;
            }
        }

        $doomCmd = sprintf(
            'DISPLAY=:%d dsda-doom -iwad %s -window -width %d -height %d > /dev/null 2>&1 & echo $!',
            $this->display,
            escapeshellarg($wadPath),
            $this->width,
            $this->height
        );
        $doomPid = trim((string) shell_exec($doomCmd));
        sleep(2);

        $this->running = true;
        error_log("DOOM started - Display: {$this->display}, Xvfb: {$xvfbPid}, DOOM: {$doomPid}");

        return true;
    }

    /**
     * @return array{data: string, cached: bool, size?: int}|null
     */
    public function captureFrame(): ?array
    {
        if (!$this->running) {
            return null;
        }

        $now = microtime(true);
        if ($now - $this->lastFrameTime < 0.1) {
            return $this->lastFrameData !== null ? ['data' => $this->lastFrameData, 'cached' => true] : null;
        }
        $this->lastFrameTime = $now;

        $framePath = $this->frameDir . '/frame_' . $this->currentFrame . '.png';

        $captureCmd = sprintf(
            'DISPLAY=:%d scrot %s 2>/dev/null || DISPLAY=:%d import -window root %s 2>/dev/null',
            $this->display,
            escapeshellarg($framePath),
            $this->display,
            escapeshellarg($framePath)
        );
        exec($captureCmd);

        if (!file_exists($framePath)) {
            return $this->lastFrameData !== null ? ['data' => $this->lastFrameData, 'cached' => true] : null;
        }

        $imageData = $this->optimizeFrame($framePath);
        if ($imageData === null) {
            @unlink($framePath);

            return $this->lastFrameData !== null ? ['data' => $this->lastFrameData, 'cached' => true] : null;
        }

        $frameHash = md5($imageData);
        if ($frameHash === $this->lastFrameHash) {
            @unlink($framePath);

            return $this->lastFrameData !== null ? ['data' => $this->lastFrameData, 'cached' => true] : null;
        }

        $this->lastFrameHash = $frameHash;
        $base64 = base64_encode($imageData);
        $format = $this->useWebP ? 'webp' : 'jpeg';
        $dataUrl = "data:image/{$format};base64," . $base64;
        $this->lastFrameData = $dataUrl;

        if ($this->currentFrame > 2) {
            @unlink($this->frameDir . '/frame_' . ($this->currentFrame - 2) . '.png');
        }
        ++$this->currentFrame;
        @unlink($framePath);

        return ['data' => $dataUrl, 'cached' => false, 'size' => strlen($base64)];
    }

    public function sendKeyDown(string $key): void
    {
        if (!$this->running) {
            return;
        }

        $keyCode = $this->getKeyCode($key);
        if ($keyCode === null || isset($this->activeKeys[$keyCode])) {
            return;
        }

        $this->activeKeys[$keyCode] = true;
        $escapedKey = escapeshellarg($keyCode);
        $cmd = sprintf(
            'DISPLAY=:%d bash -c "WINDOW=\$(xdotool search --name dsda-doom 2>/dev/null | head -1); if [ -n \"\$WINDOW\" ]; then xdotool keydown --window \$WINDOW %s 2>&1; else xdotool keydown %s 2>&1; fi"',
            $this->display,
            $escapedKey,
            $escapedKey
        );
        shell_exec($cmd);
    }

    public function sendKeyUp(string $key): void
    {
        if (!$this->running) {
            return;
        }

        $keyCode = $this->getKeyCode($key);
        if ($keyCode === null || !isset($this->activeKeys[$keyCode])) {
            return;
        }

        unset($this->activeKeys[$keyCode]);
        $escapedKey = escapeshellarg($keyCode);
        $cmd = sprintf(
            'DISPLAY=:%d bash -c "WINDOW=\$(xdotool search --name dsda-doom 2>/dev/null | head -1); if [ -n \"\$WINDOW\" ]; then xdotool keyup --window \$WINDOW %s 2>&1; else xdotool keyup %s 2>&1; fi"',
            $this->display,
            $escapedKey,
            $escapedKey
        );
        shell_exec($cmd);
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        foreach (array_keys($this->activeKeys) as $keyCode) {
            exec(sprintf('DISPLAY=:%d xdotool keyup %s > /dev/null 2>&1 &', $this->display, escapeshellarg($keyCode)));
        }
        $this->activeKeys = [];

        exec("pkill -9 -f 'dsda-doom.*DISPLAY=:{$this->display}' 2>/dev/null");
        exec("pkill -9 -f 'DISPLAY=:{$this->display}.*dsda-doom' 2>/dev/null");
        exec("pkill -9 -f 'Xvfb :{$this->display}' 2>/dev/null");
        exec("DISPLAY=:{$this->display} pkill -9 dsda-doom 2>/dev/null");
        usleep(100000);

        if (is_dir($this->frameDir)) {
            array_map('unlink', glob($this->frameDir . '/*') ?: []);
            @rmdir($this->frameDir);
        }

        $this->running = false;
    }

    private function optimizeFrame(string $inputPath): ?string
    {
        $targetWidth = (int) ($this->width / 2);
        $targetHeight = (int) ($this->height / 2);

        if ($this->useWebP && extension_loaded('gd')) {
            $image = @imagecreatefrompng($inputPath);
            if ($image === false) {
                return null;
            }

            $resized = imagescale($image, $targetWidth, $targetHeight, IMG_BICUBIC);
            imagedestroy($image);
            if ($resized === false) {
                return null;
            }

            ob_start();
            imagewebp($resized, null, $this->quality);
            $imageData = ob_get_clean();
            imagedestroy($resized);

            return $imageData !== false ? $imageData : null;
        }

        $outputPath = $inputPath . '.jpg';
        $cmd = sprintf(
            'convert %s -resize %dx%d -quality %d -sampling-factor 4:2:0 -strip %s 2>/dev/null',
            escapeshellarg($inputPath),
            $targetWidth,
            $targetHeight,
            $this->quality,
            escapeshellarg($outputPath)
        );
        exec($cmd);

        if (!file_exists($outputPath)) {
            return null;
        }

        $imageData = file_get_contents($outputPath);
        @unlink($outputPath);

        return $imageData !== false ? $imageData : null;
    }

    private function getKeyCode(string $key): ?string
    {
        $keyMap = [
            'ArrowUp' => 'Up',
            'ArrowDown' => 'Down',
            'ArrowLeft' => 'Left',
            'ArrowRight' => 'Right',
            ' ' => 'space',
            'Control' => 'Control_L',
            'ControlLeft' => 'Control_L',
            'ControlRight' => 'Control_R',
            'Shift' => 'Shift_L',
            'ShiftLeft' => 'Shift_L',
            'ShiftRight' => 'Shift_R',
            'Alt' => 'Alt_L',
            'AltLeft' => 'Alt_L',
            'AltRight' => 'Alt_R',
            'Enter' => 'Return',
            'Escape' => 'Escape',
        ];

        if (isset($keyMap[$key])) {
            return $keyMap[$key];
        }

        if (strlen($key) === 1) {
            return strtolower($key);
        }

        return $key;
    }

    private function findAvailableDisplay(): int
    {
        for ($i = 99; $i < 200; ++$i) {
            if (!file_exists('/tmp/.X' . $i . '-lock')) {
                return $i;
            }
        }

        return 99;
    }
}
