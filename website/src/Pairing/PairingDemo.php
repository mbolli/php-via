<?php

declare(strict_types=1);

namespace PhpVia\Website\Pairing;

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use PhpVia\Website\StaticPage;

/**
 * The phone-pairing demo in the homepage hero: the homepage component and the page at /pair/{code}
 * join the scope "pair:<code>", and a swatch picked on either one sets the pair's colour and broadcasts it.
 */
final class PairingDemo {
    public const string ROUTE = '/pair/{code}';

    private const int PRUNE_EVERY_MS = 60_000;

    /**
     * @param string $fallbackOrigin scheme and host for links when the request did not say, without a trailing slash
     */
    public function __construct(
        private Via $app,
        private PairingStore $store,
        private string $fallbackOrigin,
    ) {}

    /**
     * Register the phone page and the timer that prunes idle pairs.
     */
    public function register(): void {
        $this->app->page(self::ROUTE, $this->phonePage(...));

        $this->app->setInterval(function (): void {
            $this->store->prune(fn (string $code): bool => $this->app->getContextsByScope(PairingStore::scope($code)) !== []);
        }, self::PRUNE_EVERY_MS);
    }

    /**
     * Set up the homepage component: a pairing code for the tab, its URL and the widget.
     * The browser draws the QR code of the URL (sb-qr-code, public/vendor/starbase).
     * Mount it with a name, so its ID and its action survive a revival of the page.
     */
    public function component(Context $c): void {
        // A named component's ID is derived from its page's, so it is the same after a revival.
        $origin = $c->getRequestAttribute(RequestOrigin::ATTRIBUTE);
        $code = $this->store->codeForTab($c->getId(), \is_string($origin) ? $origin : $this->fallbackOrigin);
        $url = ($this->store->origin($code) ?? $this->fallbackOrigin) . '/pair/' . $code;
        $scope = PairingStore::scope($code);

        $c->scope($scope);
        $pick = $this->pickAction($c, $code);

        // Only the colour changes in this view, so an update that would resend it unchanged (the
        // SSE connect, a broadcast to the homepage's route) renders nothing and sends no patch.
        $sent = false;
        $sentColour = null;
        $c->view(function (bool $isUpdate) use ($c, $code, $scope, $url, $pick, &$sent, &$sentColour): string {
            $widget = $this->widget($code, $pick);
            if ($isUpdate && $sent && $widget['colour'] === $sentColour) {
                return '';
            }
            $sent = true;
            $sentColour = $widget['colour'];

            return $c->render('components/pairing.html.twig', ['scope' => $scope, 'url' => $url, 'widget' => $widget]);
        });
    }

    private function phonePage(Context $c, string $code): void {
        if (!PairingStore::isWellFormed($code) || !$this->store->has($code)) {
            StaticPage::view($c, 'pages/pair.html.twig', ['found' => false]);

            return;
        }

        $this->store->touch($code);
        $scope = PairingStore::scope($code);
        $c->scope($scope);
        $pick = $this->pickAction($c, $code);

        // Updates render only the widget block; Datastar morphs it by its id.
        $c->view('pages/pair.html.twig', fn (): array => [
            'found' => true,
            'scope' => $scope,
            'widget' => $this->widget($code, $pick),
        ], block: 'pair_widget');
    }

    /**
     * A TAB action: the page finds actions through its own scopes, its route, GLOBAL and its
     * components' TAB actions, never through a component's custom scope.
     */
    private function pickAction(Context $c, string $code): Action {
        return $c->action(function (Context $c) use ($code): void {
            $colour = $c->input('colour');
            $before = $this->store->colour($code);
            if (\is_string($colour) && $this->store->setColour($code, $colour) && $colour !== $before) {
                $this->app->broadcast(PairingStore::scope($code));
            }
        }, 'pick', Scope::TAB);
    }

    /**
     * @return array{colour: null|string, swatches: list<array{name: string, url: string, active: bool}>}
     */
    private function widget(string $code, Action $pick): array {
        $colour = $this->store->colour($code);
        $swatches = [];
        foreach (PairingStore::COLOURS as $name) {
            $swatches[] = ['name' => $name, 'url' => $pick->url() . '?colour=' . $name, 'active' => $name === $colour];
        }

        return ['colour' => $colour, 'swatches' => $swatches];
    }
}
