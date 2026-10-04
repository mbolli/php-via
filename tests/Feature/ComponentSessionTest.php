<?php

declare(strict_types=1);

use Mbolli\PhpVia\Attributes\Signal as SignalAttr;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * A component belongs to the visitor of its page, so it has the page's session: its session id,
 * session data and SESSION signals are the page's.
 */

const COMPONENT_SESSION_ID = 'beef0000beef0000beef0000beef0000';

final class ComponentSessionThemeCard {
    #[SignalAttr(Scope::SESSION)]
    public string $theme = 'light';

    public function view(Context $ctx): void {
        $ctx->view(fn () => '<p>' . $this->theme . '</p>');
    }
}

describe('Components and the session', function (): void {
    test('a component and a nested component report the page session', function (): void {
        $app = createVia();
        $page = new Context('P', '/p', $app, null, COMPONENT_SESSION_ID);
        $seen = [];

        $page->component(function (Context $outer) use (&$seen): void {
            $seen['outer'] = $outer->getSessionId();
            $outer->component(function (Context $inner) use (&$seen): void {
                $seen['inner'] = $inner->getSessionId();
                $inner->view(fn () => '');
            }, 'inner');
            $outer->view(fn () => '');
        }, 'outer');

        expect($seen)->toBe(['outer' => COMPONENT_SESSION_ID, 'inner' => COMPONENT_SESSION_ID]);
    });

    test('session data written in a component is the page session data', function (): void {
        $app = createVia();
        $page = new Context('P', '/p', $app, null, COMPONENT_SESSION_ID);
        $page->setSessionData('kept', 'yes');

        $page->component(function (Context $k): void {
            $k->setSessionData('x', 1);
            $k->clearSessionData('kept');
            $k->view(fn () => '');
        }, 'k');

        expect($page->sessionData('x'))->toBe(1)
            ->and($page->sessionData('kept'))->toBeNull()
        ;
    });

    test('a component declares SESSION signals in the page session scope', function (): void {
        $app = createVia();
        $page = new Context('P', '/p', $app, null, COMPONENT_SESSION_ID);

        $page->component(function (Context $k): void {
            $k->signal('dark', 'mode', Scope::SESSION);
            $k->view(fn () => '');
        }, 'closure');
        $page->component(ComponentSessionThemeCard::class, 'card');

        $sessionScope = Scope::sessionScope(COMPONENT_SESSION_ID);
        $components = array_values($page->getComponentRegistry());

        expect($components[0]->getSignal('mode')?->getScope())->toBe($sessionScope)
            ->and($components[1]->getSignal('theme')?->getScope())->toBe($sessionScope)
        ;
    });
});
