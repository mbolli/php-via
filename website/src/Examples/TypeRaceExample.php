<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

final class TypeRaceExample
{
    public const string SLUG = 'type-race';

    /** @var string[] */
    private const array SNIPPETS = [
        '$app->page(\'/hello\', function (Context $c): void {
    $name = $c->signal(\'World\', \'name\');
    $c->view(fn () => "<h1>Hello, {$name->string()}!</h1>");
});',
        'function fibonacci(int $n): int {
    if ($n <= 1) return $n;
    return fibonacci($n - 1) + fibonacci($n - 2);
}',
        '$result = array_filter(
    array_map(fn ($x) => $x ** 2, range(1, 10)),
    fn ($x) => $x % 2 === 0
);',
        'class Signal {
    public function __construct(
        private mixed $value,
        private readonly string $id,
    ) {}
}',
        'match (true) {
    $score >= 90 => \'A\',
    $score >= 80 => \'B\',
    $score >= 70 => \'C\',
    default      => \'F\',
};',
    ];

    private const int COUNTDOWN_SECONDS = 3;
    private const int MAX_RACERS_PER_RACE = 4;

    /** @var string[] */
    private const array SUMMARY = [
        '<strong>Race state machine</strong> — each race moves through <code>waiting → countdown → racing → done</code>. All transitions happen server-side; the client just sends keystrokes.',
        '<strong>Custom scope per race</strong> isolates each race\'s broadcasts. Multiple races can run simultaneously — joining players are routed to an open race automatically.',
        '<strong>Progress is server-computed</strong> — the client sends only the latest typed text; the server counts matching leading characters against the snippet. No snippet logic ships to the browser.',
        '<strong>OpenSwoole countdown timer</strong> ticks 3…2…1 before the race starts, broadcasting to all racers each tick. The race clock also tracks elapsed WPM per player.',
        '<strong>SESSION identity</strong> gives each racer a persistent name across tabs and refreshes. Joining the same race twice from two tabs counts as two racers.',
        '<strong>Anti-cheat by design</strong> — the server holds the snippet truth and computes every progress value. Sending the wrong text just gives zero progress.',
    ];

    /** @var array<string, list<array{name: string, desc?: string, type?: string, scope?: string, default?: string}>> */
    private const array ANATOMY = [
        'signals' => [
            ['name' => 'username', 'type' => 'string', 'scope' => 'SESSION', 'desc' => 'Racer handle, auto-assigned. Persists across tabs.'],
            ['name' => 'typedText', 'type' => 'string', 'scope' => 'TAB', 'desc' => 'Current textarea value. Sent to server on every input event; never broadcast.'],
            ['name' => 'raceStatus', 'type' => 'string', 'scope' => 'Custom race scope', 'desc' => '"waiting" | "countdown" | "racing" | "done". Controls which UI panel renders.'],
            ['name' => 'countdown', 'type' => 'int', 'scope' => 'Custom race scope', 'desc' => '3…2…1 before start. Broadcast each tick.'],
        ],
        'actions' => [
            ['name' => 'updateProgress', 'desc' => 'Called on every input event. Server counts correct leading chars, updates this racer\'s progress and WPM, broadcasts to race scope.'],
            ['name' => 'joinRace', 'desc' => 'Joins the current open race (or creates one). Starts countdown timer when MIN_RACERS reached.'],
        ],
        'views' => [
            ['name' => 'type_race.html.twig', 'desc' => 'Lobby, countdown overlay, racing textarea + progress bars, and results podium. All driven by raceStatus signal.'],
        ],
    ];

    /** @var list<array{label: string, url: string}> */
    private const array GITHUB_LINKS = [
        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/TypeRaceExample.php'],
        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/type_race.html.twig'],
    ];

    // ── Race state ─────────────────────────────────────────────────────────────

    /**
     * @var array<string, array{
     *   status: string,
     *   snippet: string,
     *   racers: array<string, array{name: string, progress: int, wpm: float, finished: bool, finishRank: int}>,
     *   countdownValue: int,
     *   timerId: int|null,
     *   startTime: int|null,
     *   finishCount: int,
     * }>
     */
    private static array $races = [];

    /** @var array<string, string> contextId => raceId */
    private static array $contextRace = [];

    private static int $raceCounter = 0;

    // ── Registration ───────────────────────────────────────────────────────────

    public static function register(Via $app): void
    {
        $app->page('/examples/type-race', function (Context $c) use ($app): void {
            $contextId = $c->getId();

            // Session-scoped username
            $usernameSignal = $c->signal('', 'username', Scope::SESSION);
            if ($usernameSignal->getValue() === '') {
                $animals = ['Cheetah', 'Falcon', 'Mamba', 'Puma', 'Viper', 'Lynx', 'Jaguar', 'Raptor'];
                $usernameSignal->setValue($animals[array_rand($animals)] . random_int(10, 99));
            }
            $username = $usernameSignal->getValue();

            // Join an open race (or create one)
            $raceId = self::joinRace($contextId, $username);
            $race   = &self::$races[$raceId];
            $scope  = Scope::build('example:typerace', $raceId);
            $c->addScope($scope);

            // Notify existing players in the lobby that someone just joined
            if (\count($race['racers']) > 1) {
                $app->broadcast($scope);
            }
            unset($race);

            // TAB-only input
            $typedText = $c->signal('', 'typedText');

            // ── Actions ───────────────────────────────────────────────────────

            $updateProgress = $c->action(function (Context $ctx) use (
                $raceId, $contextId, $typedText, $app,
            ): void {
                $raceScope = Scope::build('example:typerace', $raceId);
                $race = &self::$races[$raceId];
                if ($race['status'] !== 'racing') {
                    return;
                }
                if (!isset($race['racers'][$contextId])) {
                    return;
                }

                $text    = $typedText->getValue();
                $snippet = $race['snippet'];
                $len     = \strlen($snippet);

                // Count leading correct characters
                $correct = 0;
                for ($i = 0; $i < min(\strlen($text), $len); $i++) {
                    if ($text[$i] === $snippet[$i]) {
                        $correct++;
                    } else {
                        break;
                    }
                }

                $progress = (int) round(($correct / $len) * 100);

                // WPM: words = chars / 5, time in minutes
                $elapsed = time() - (int) $race['startTime'];
                $wpm     = $elapsed > 0 ? round(($correct / 5) / ($elapsed / 60)) : 0;

                $racer = &$race['racers'][$contextId];
                $racer['progress'] = $progress;
                $racer['wpm']      = (float) $wpm;

                if ($progress >= 100 && !$racer['finished']) {
                    $racer['finished']    = true;
                    $racer['wpm']         = (float) $wpm;
                    $race['finishCount']++;
                    $racer['finishRank']  = $race['finishCount'];

                    // End race when all racers finish
                    $allDone = array_reduce(
                        $race['racers'],
                        fn (bool $carry, array $r) => $carry && $r['finished'],
                        true,
                    );
                    if ($allDone) {
                        $race['status'] = 'done';
                        if ($race['timerId'] !== null) {
                            Timer::clear($race['timerId']);
                            $race['timerId'] = null;
                        }
                    }
                }

                $app->broadcast($raceScope);
                unset($racer, $race);
            }, 'updateProgress');

            $startRace = $c->action(function () use ($raceId, $app): void {
                if (!isset(self::$races[$raceId])) {
                    return;
                }
                $race = &self::$races[$raceId];
                if ($race['status'] !== 'waiting' || \count($race['racers']) < 1) {
                    return;
                }
                $scope = Scope::build('example:typerace', $raceId);
                unset($race);
                self::beginCountdown($raceId, $app);
                $app->broadcast($scope);
            }, 'startRace');

            $newRace = $c->action(function () use ($contextId, $app): void {
                $raceId = self::$contextRace[$contextId] ?? null;
                if ($raceId === null || !isset(self::$races[$raceId])) {
                    return;
                }
                $race = &self::$races[$raceId];
                // Stop any running timer
                if ($race['timerId'] !== null) {
                    Timer::clear($race['timerId']);
                    $race['timerId'] = null;
                }
                // Reset all racer progress
                foreach ($race['racers'] as $id => $_) {
                    $race['racers'][$id] = self::newRacer($race['racers'][$id]['name']);
                }
                // Reset race state with a new snippet
                $race['status']         = 'waiting';
                $race['snippet']        = self::SNIPPETS[array_rand(self::SNIPPETS)];
                $race['countdownValue'] = self::COUNTDOWN_SECONDS;
                $race['startTime']      = null;
                $race['finishCount']    = 0;
                unset($race);

                // Absorb lone waiters from other races so nobody is stuck waiting alone.
                // Only pull from races still in 'waiting' state (not mid-race).
                $newScope = Scope::build('example:typerace', $raceId);
                foreach (self::$races as $otherId => $otherRace) {
                    if ($otherId === $raceId || $otherRace['status'] !== 'waiting') {
                        continue;
                    }
                    $oldScope = Scope::build('example:typerace', $otherId);
                    foreach ($app->getContextsByScope($oldScope) as $ctx) {
                        $pid = $ctx->getId();
                        // Move racer data
                        self::$races[$raceId]['racers'][$pid] = self::newRacer($otherRace['racers'][$pid]['name'] ?? $pid);
                        self::$contextRace[$pid] = $raceId;
                        // Switch context to new scope so future broadcasts reach them
                        $ctx->removeScope($oldScope);
                        $ctx->addScope($newScope);
                    }
                    // Clean up the now-empty race
                    unset(self::$races[$otherId]);
                }

                $app->broadcast($newScope);
            }, 'newRace');

            // ── Cleanup ───────────────────────────────────────────────────────

            $c->onDisconnect(function () use ($contextId, $app): void {
                $raceId = self::$contextRace[$contextId] ?? null;
                unset(self::$contextRace[$contextId]);
                if ($raceId === null || !isset(self::$races[$raceId])) {
                    return;
                }
                unset(self::$races[$raceId]['racers'][$contextId]);
                if (self::$races[$raceId]['racers'] === []) {
                    if (self::$races[$raceId]['timerId'] !== null) {
                        Timer::clear(self::$races[$raceId]['timerId']);
                    }
                    unset(self::$races[$raceId]);
                } else {
                    $app->broadcast(Scope::build('example:typerace', $raceId));
                }
            });

            // ── View ──────────────────────────────────────────────────────────

            $c->view(function () use ($c, $contextId, $username, $typedText, $updateProgress, $startRace, $newRace): string {
                $raceId = self::$contextRace[$contextId] ?? '';
                return $c->render('examples/type_race.html.twig', [
                'title'           => '⌨️ Type Race',
                'description'     => 'Race to type a PHP snippet first. Progress, WPM, and countdown all live-update for every racer — no client logic.',
                'summary'         => self::SUMMARY,
                'anatomy'         => self::ANATOMY,
                'githubLinks'     => self::GITHUB_LINKS,
                'sourceFile'      => 'type_race.php',
                'templateFiles'   => ['type_race.html.twig'],
                'raceId'          => $raceId,
                'snippet'         => self::$races[$raceId]['snippet'] ?? '',
                'status'          => self::$races[$raceId]['status'] ?? 'waiting',
                'countdownValue'  => self::$races[$raceId]['countdownValue'] ?? self::COUNTDOWN_SECONDS,
                'racers'          => self::$races[$raceId]['racers'] ?? [],
                'username'        => $username,
                'contextId'       => $contextId,
                'typedTextId'     => $typedText->id(),
                'updateUrl'       => $updateProgress->url(),
                'startRaceUrl'    => $startRace->url(),
                'newRaceUrl'      => $newRace->url(),
            ]);
            }, block: 'demo', cacheUpdates: false);
        });
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function joinRace(string $contextId, string $username): string
    {
        // Find an open race with space
        foreach (self::$races as $raceId => $race) {
            if ($race['status'] === 'waiting' && \count($race['racers']) < self::MAX_RACERS_PER_RACE) {
                self::$races[$raceId]['racers'][$contextId] = self::newRacer($username);
                self::$contextRace[$contextId]              = $raceId;

                return $raceId;
            }
        }

        // Create a new race
        $raceId = 'race-' . (++self::$raceCounter);
        self::$races[$raceId] = [
            'status'         => 'waiting',
            'snippet'        => self::SNIPPETS[array_rand(self::SNIPPETS)],
            'racers'         => [$contextId => self::newRacer($username)],
            'countdownValue' => self::COUNTDOWN_SECONDS,
            'timerId'        => null,
            'startTime'      => null,
            'finishCount'    => 0,
        ];
        self::$contextRace[$contextId] = $raceId;

        return $raceId;
    }

    /** @return array{name: string, progress: int, wpm: float, finished: bool, finishRank: int} */
    private static function newRacer(string $username): array
    {
        return ['name' => $username, 'progress' => 0, 'wpm' => 0.0, 'finished' => false, 'finishRank' => 0];
    }

    private static function beginCountdown(string $raceId, Via $app): void
    {
        $race = &self::$races[$raceId];
        if ($race['status'] !== 'waiting') {
            return;
        }

        $race['status']         = 'countdown';
        $race['countdownValue'] = self::COUNTDOWN_SECONDS;

        $scope = Scope::build('example:typerace', $raceId);

        $race['timerId'] = Timer::tick(1000, function () use ($raceId, $scope, $app): void {
            if (!isset(self::$races[$raceId])) {
                return;
            }
            $race = &self::$races[$raceId];

            $race['countdownValue']--;

            if ($race['countdownValue'] <= 0) {
                $race['status']    = 'racing';
                $race['startTime'] = time();
                if ($race['timerId'] !== null) {
                    Timer::clear($race['timerId']);
                    $race['timerId'] = null;
                }
            }

            $app->broadcast($scope);
            unset($race);
        });
    }
}
