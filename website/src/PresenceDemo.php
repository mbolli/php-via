<?php

declare(strict_types=1);

namespace PhpVia\Website;

use Mbolli\PhpVia\Context;
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
     *
     * @param string $tabId the ID of the page the component is on
     */
    public function component(Context $c, string $tabId): void {
        $c->scope(self::SCOPE);
        $c->view(fn (bool $isUpdate): string => $c->render('components/presence.html.twig', [
            'count' => $this->count($tabId, $isUpdate),
        ]));
    }

    /**
     * The first render comes before the viewer's own stream connects, so it counts that tab too.
     * Update renders are cached for the whole scope, so they count only connected tabs.
     */
    private function count(string $tabId, bool $isUpdate): int {
        $clients = $this->app->getClients();
        $ownTab = !$isUpdate && !isset($clients[$tabId]) ? 1 : 0;

        return \count($clients) + $ownTab;
    }
}
