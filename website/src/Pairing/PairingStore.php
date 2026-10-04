<?php

declare(strict_types=1);

namespace PhpVia\Website\Pairing;

/**
 * The pairs of the homepage's phone-pairing demo: one shared colour per pairing code.
 *
 * Process-local, which is fine while the website runs a single worker.
 */
final class PairingStore {
    /** The five swatches, in the order the widget shows them */
    public const array COLOURS = ['blue', 'orange', 'yellow', 'green', 'violet'];

    public const string DEFAULT_COLOUR = 'blue';

    public const int IDLE_SECONDS = 1800;

    public const int MAX_PAIRS = 4000;

    public const int CODE_LENGTH = 10;

    /** Crockford's base32 in lower case: URL-safe, 5 bits per character, no i, l, o or u to misread */
    private const string ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    /** @var array<string, array{colour: string, lastSeen: int, tab: null|string, origin: string}> by code, least recently seen first */
    private array $pairs = [];

    /** @var array<string, string> code by the ID of the homepage component that opened it */
    private array $tabs = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param null|\Closure(): int $clock current Unix time, replaceable in tests
     */
    public function __construct(?\Closure $clock = null) {
        $this->clock = $clock ?? time(...);
    }

    public static function scope(string $code): string {
        return 'pair:' . $code;
    }

    public static function isWellFormed(string $code): bool {
        return preg_match('/^[0-9a-hjkmnp-tv-z]{' . self::CODE_LENGTH . '}$/', $code) === 1;
    }

    /**
     * The code of a homepage tab: the one it already has while that pair lives, so a revived tab
     * keeps its pairing, or a new one announced under $origin.
     */
    public function codeForTab(string $tabId, string $origin): string {
        $code = $this->tabs[$tabId] ?? null;
        if ($code !== null && isset($this->pairs[$code])) {
            $this->touch($code);

            return $code;
        }

        $code = $this->create($origin);
        $this->pairs[$code]['tab'] = $tabId;
        $this->tabs[$tabId] = $code;

        return $code;
    }

    /**
     * Open a pair that no tab owns and return its code.
     */
    public function create(string $origin): string {
        do {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; ++$i) {
                $code .= self::ALPHABET[random_int(0, 31)];
            }
        } while (isset($this->pairs[$code]));

        $this->pairs[$code] = ['colour' => self::DEFAULT_COLOUR, 'lastSeen' => ($this->clock)(), 'tab' => null, 'origin' => $origin];
        while (\count($this->pairs) > self::MAX_PAIRS) {
            $this->forget((string) array_key_first($this->pairs));
        }

        return $code;
    }

    public function has(string $code): bool {
        return isset($this->pairs[$code]);
    }

    public function colour(string $code): ?string {
        return $this->pairs[$code]['colour'] ?? null;
    }

    /**
     * The scheme and host the pair's URL was announced under, without a trailing slash.
     */
    public function origin(string $code): ?string {
        return $this->pairs[$code]['origin'] ?? null;
    }

    /**
     * Set the pair's colour. False when the pair is gone or the colour is not a swatch.
     */
    public function setColour(string $code, string $colour): bool {
        if (!isset($this->pairs[$code]) || !\in_array($colour, self::COLOURS, true)) {
            return false;
        }

        $this->pairs[$code]['colour'] = $colour;
        $this->touch($code);

        return true;
    }

    /**
     * Mark the pair as seen now. Moving it to the end keeps the map ordered by lastSeen.
     */
    public function touch(string $code): void {
        $pair = $this->pairs[$code] ?? null;
        if ($pair === null) {
            return;
        }

        unset($this->pairs[$code]);
        $pair['lastSeen'] = ($this->clock)();
        $this->pairs[$code] = $pair;
    }

    /**
     * Drop the pairs nobody has seen for IDLE_SECONDS. A pair $inUse reports is kept and touched.
     *
     * @param null|\Closure(string): bool $inUse whether a tab still shows the pair
     *
     * @return int the number of pairs dropped
     */
    public function prune(?\Closure $inUse = null): int {
        $cutoff = ($this->clock)() - self::IDLE_SECONDS;
        $idle = [];
        foreach ($this->pairs as $code => $pair) {
            if ($pair['lastSeen'] > $cutoff) {
                break;
            }
            $idle[] = (string) $code;
        }

        $dropped = 0;
        foreach ($idle as $code) {
            if ($inUse !== null && $inUse($code)) {
                $this->touch($code);

                continue;
            }
            $this->forget($code);
            ++$dropped;
        }

        return $dropped;
    }

    public function count(): int {
        return \count($this->pairs);
    }

    private function forget(string $code): void {
        $tab = $this->pairs[$code]['tab'] ?? null;
        if ($tab !== null) {
            unset($this->tabs[$tab]);
        }
        unset($this->pairs[$code]);
    }
}
