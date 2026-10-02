<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/**
 * Minimal reusable component that demonstrates the composition API.
 *
 * Used three times inside CompositionDemo (cats, dogs, parrots).
 * Each instance gets its own globally-shared counter (ids such as global_cats_votes____kn).
 * The component namespace is prepended to the signal ID by SignalFactory, so each animal
 * has an independent counter that persists and syncs across all users.
 */
final class VoteWidget {
    // atomic: true. vote() is ++$this->votes, which without it is a read-modify-write
    // across workers and drops concurrent votes.
    #[Signal(Scope::GLOBAL, atomic: true)]
    public int $votes = 0;

    public function view(Context $ctx): void {
        $ctx->view('examples/composition_vote.html.twig');
    }

    #[Action]
    public function vote(Context $ctx): void {
        ++$this->votes;
    }
}
