<?php

declare(strict_types=1);

namespace PhpVia\Website;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/**
 * The presence line in the homepage hero: "N people on this website right now", counting the tabs
 * whose stream is connected. Its own scope, so a visitor arriving or leaving re-renders the
 * indicators and not every open page.
 */
final class PresenceDemo {
    public const string SCOPE = 'site:presence';

    private const int DEBOUNCE_MS = 200;

    private ?int $timer = null;

    public function __construct(private Via $app) {}

    /**
     * Broadcast to the indicators when a tab connects or leaves. Debounced: a burst of connects, such
     * as a load test, collapses into one broadcast instead of N broadcasts that each re-render N indicators.
     */
    public function register(): void {
        $broadcast = function (): void {
            if ($this->timer !== null) {
                Timer::clear($this->timer);
            }
            $this->timer = Timer::after(self::DEBOUNCE_MS, function (): void {
                $this->timer = null;
                $this->app->broadcast(self::SCOPE);
            });
        };

        $this->app->onClientConnect($broadcast);
        $this->app->onClientDisconnect($broadcast);
    }

    /**
     * Set up the homepage component.
     */
    public function component(Context $c): void {
        $c->scope(self::SCOPE);
        $c->view('components/presence.html.twig', fn (): array => [
            'count' => $this->count(),
        ], shareRender: true);
    }

    /**
     * The connected tabs, and at least the viewer. The viewer's own tab is not counted before its
     * stream connects: its first sync shows the scope's cached render, so the count would drop back.
     */
    private function count(): int {
        return max(1, $this->app->countClients(Scope::GLOBAL));
    }
}
