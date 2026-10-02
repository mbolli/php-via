<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Persist;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * Demonstrates all four reactive shapes of the composition API:
 *
 *  #[Signal]:                 TAB-scoped, client-writable reactive property
 *  #[Persist]:                server-only instance property, never a signal
 *  #[Signal(Scope::SESSION)]: SESSION-scoped signal, auto-broadcasts to all user tabs
 *  #[Signal(Scope::GLOBAL)]:  GLOBAL-scoped signal, auto-broadcasts to all users
 *
 * Also demonstrates #[Action] with an optional name override and three
 * VoteWidget components embedded as $ctx->component(VoteWidget::class, …).
 */
final class CompositionDemo {
    /** Client-writable text input, held temporarily before saveName promotes it. */
    #[Signal]
    public string $nameInput = '';

    /** Promoted name. SESSION scope auto-broadcasts to all tabs of this user. */
    #[Signal(Scope::SESSION)]
    public string $name = 'Anonymous';

    /** Per-tab visible counter. Grows non-linearly due to the $multiplier. */
    #[Signal]
    public int $count = 0;

    /** Server-only multiplier. Not a signal, invisible to client. Proves #[Persist] survives between actions. */
    #[Persist]
    public int $multiplier = 1;

    /**
     * Total clicks by ALL users. GLOBAL scope → auto-broadcasts to every session.
     *
     * atomic: true because every action here does ++$this->totalClicks. Without it the mount
     * reads the property before the action and assigns it back after, so two workers handling a
     * click at the same moment would each write back the same total and count one click.
     */
    #[Signal(Scope::GLOBAL, atomic: true)]
    public int $totalClicks = 0;

    public function view(Context $ctx): void {
        $cats = $ctx->component(VoteWidget::class, 'cats');
        $dogs = $ctx->component(VoteWidget::class, 'dogs');
        $parrots = $ctx->component(VoteWidget::class, 'parrots');

        // Per-client render, declared: `count` and `nameInput` are TAB-scoped, so the output
        // differs per tab. The SESSION and GLOBAL signals put this context in shared scopes, which
        // is what makes the declaration worth making explicit rather than leaving to scope order.
        // The widgets render with the page, so a page re-render carries their current votes.
        $ctx->view(fn (): string => $ctx->render('examples/composition.html.twig', [
            'title' => '🏗️ Composition API',
            'description' => 'Class-based page and component API using PHP attributes: <code>#[Signal]</code>, <code>#[Signal(Scope::SESSION)]</code>, <code>#[Signal(Scope::GLOBAL)]</code>, <code>#[Persist]</code>, and <code>#[Action]</code>.',
            'summary' => [
                '<strong>#[Signal]</strong> creates a TAB-scoped reactive signal backed by a client-visible store entry. Client-writable via <code>data-bind</code>. Each browser tab has its own isolated copy.',
                '<strong>#[Persist]</strong> is a plain server-side instance property, not a signal, not visible to the client. Because the class instance lives for the lifetime of the tab connection, it persists across action calls.',
                '<strong>#[Signal(Scope::SESSION)]</strong> is a SESSION-scoped signal. Shared across all open tabs of the same browser session. Saving a name here broadcasts it to the other tabs.',
                '<strong>#[Signal(Scope::GLOBAL)]</strong> is a GLOBAL-scoped signal. Shared across every connected user. The <code>totalClicks</code> counter increments for all users simultaneously, regardless of which tab triggered it.',
                '<strong>#[Action]</strong> marks a public method as a client-callable action. The optional <code>name:</code> argument overrides the URL slug: <code>resetTab</code> is exposed as <code>/_action/reset-tab</code>.',
                '<strong>Components + GLOBAL signals</strong>: <code>VoteWidget</code> is mounted three times with <code>#[Signal(Scope::GLOBAL)] votes</code>. SignalFactory puts the component namespace into scoped signal IDs, giving each animal its own persistent global counter: <code>global_cats_votes____kn</code>, <code>global_dogs_votes____kn</code>, <code>global_parrots_votes____kn</code>.',
            ],
            'anatomy' => [
                'signals' => [
                    ['name' => 'nameInput', 'type' => 'string', 'scope' => 'TAB', 'default' => "''", 'desc' => 'Client-writable input bound via data-bind. Holds the typed name until saveName is called.'],
                    ['name' => 'name', 'type' => 'string', 'scope' => 'SESSION', 'default' => 'Anonymous', 'desc' => 'Promoted from nameInput by saveName. SESSION scope auto-broadcasts to all tabs of this session.'],
                    ['name' => 'count', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Per-tab counter. Each click adds the current multiplier: grows +1, +2, +3… proving #[Persist] survives between actions.'],
                    ['name' => 'multiplier', 'type' => 'int', 'scope' => 'Persist', 'default' => '1', 'desc' => 'Server-only instance property (not a signal). Invisible to the client. Grows by 1 on each increment call.'],
                    ['name' => 'totalClicks', 'type' => 'int', 'scope' => 'GLOBAL', 'default' => '0', 'desc' => 'Counts every action call by every user. GLOBAL scope auto-broadcasts to all connected sessions.'],
                    ['name' => 'votes (VoteWidget)', 'type' => 'int', 'scope' => 'GLOBAL', 'default' => '0', 'desc' => 'Per-animal vote counter. SignalFactory namespaces the ID: global_cats_votes____kn, global_dogs_votes____kn, global_parrots_votes____kn. Persistent and shared across all users.'],
                ],
                'actions' => [
                    ['name' => 'increment', 'desc' => 'Adds multiplier to count, then bumps both multiplier and totalClicks.'],
                    ['name' => 'reset-tab', 'desc' => 'Resets count and multiplier for this tab only. Custom slug via #[Action(name: \'reset-tab\')].'],
                    ['name' => 'saveName', 'desc' => 'Copies nameInput → name (SESSION signal). Auto-broadcasts to all of this user\'s tabs.'],
                    ['name' => 'vote', 'desc' => 'VoteWidget action. Independent per instance: cats.vote, dogs.vote, parrots.vote. Votes persist globally across all users.'],
                ],
                'views' => [
                    ['name' => 'composition.html.twig', 'desc' => 'Page shell. Renders all signals and three embedded VoteWidget components.'],
                    ['name' => 'composition_vote.html.twig', 'desc' => 'VoteWidget component template. Uses the auto-namespaced votes signal and vote action.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/CompositionDemo.php'],
                ['label' => 'View component class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/VoteWidget.php'],
                ['label' => 'View page template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/composition.html.twig'],
                ['label' => 'View component template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/composition_vote.html.twig'],
            ],
            'cats' => $cats(),
            'dogs' => $dogs(),
            'parrots' => $parrots(),
        ]), cacheUpdates: false);
    }

    #[Action]
    public function increment(Context $ctx): void {
        $this->count += $this->multiplier;
        ++$this->multiplier;
        ++$this->totalClicks;
    }

    #[Action(name: 'reset-tab')]
    public function resetTab(Context $ctx): void {
        $this->count = 0;
        $this->multiplier = 1;
        ++$this->totalClicks;
    }

    #[Action]
    public function saveName(Context $ctx): void {
        if ($this->nameInput !== '') {
            $this->name = $this->nameInput;
        }
        ++$this->totalClicks;
    }

    public static function register(Via $app): void {
        $app->mount(self::class, '/examples/composition');
    }
}
