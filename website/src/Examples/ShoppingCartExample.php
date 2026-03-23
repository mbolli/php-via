<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class ShoppingCartExample
{
    public const string SLUG = 'shopping-cart';

    /** @var list<array{id: int, name: string, price: float, emoji: string}> */
    private const array PRODUCTS = [
        ['id' => 1, 'name' => 'Artisanal Bit Bucket (12L)',                  'price' => 4.99,    'emoji' => '🪣'],
        ['id' => 2, 'name' => 'Blockchain-Certified Rubber Duck',             'price' => 29.99,   'emoji' => '🦆'],
        ['id' => 3, 'name' => 'Enterprise-Grade Left-Pad Module',             'price' => 1999.00, 'emoji' => '📦'],
        ['id' => 4, 'name' => 'Self-Documenting Code (PDF, signed)',          'price' => 0.00,    'emoji' => '📄'],
        ['id' => 5, 'name' => 'Quantum-Entangled HDMI Cable (5m)',            'price' => 79.99,   'emoji' => '🔌'],
        ['id' => 6, 'name' => 'Gluten-Free Null Pointer',                     'price' => 0.01,    'emoji' => '⭕'],
        ['id' => 7, 'name' => 'Infinite Loop Coffee Mug',                     'price' => 14.99,   'emoji' => '☕'],
        ['id' => 8, 'name' => 'Zero-Day Vulnerability Insurance (Annual)',    'price' => 999.99,  'emoji' => '🛡️'],
    ];

    /** @var array<string, array<int, array{id: int, name: string, price: float, emoji: string, qty: int}>> sessionId => items */
    private static array $carts = [];

    public static function register(Via $app): void
    {
        $app->page('/examples/shopping-cart', function (Context $c) use ($app): void {
            $sessionId = $c->getSessionId() ?? $c->getId();
            $cartScope = Scope::build('cart', $sessionId);
            $c->addScope($cartScope);

            self::$carts[$sessionId] ??= [];

            $c->onDisconnect(function () use ($sessionId, $cartScope, $app): void {
                // Clean up cart memory only when the last tab from this session disconnects
                if ($app->getContextsByScope($cartScope) === []) {
                    unset(self::$carts[$sessionId]);
                }
            });

            $addItem = $c->action(function () use ($sessionId, $cartScope, $app): void {
                $id      = (int) ($_GET['id'] ?? 0);
                $product = self::findProduct($id);

                if ($product === null) {
                    return;
                }

                if (isset(self::$carts[$sessionId][$id])) {
                    self::$carts[$sessionId][$id]['qty']++;
                } else {
                    self::$carts[$sessionId][$id] = [...$product, 'qty' => 1];
                }

                $app->broadcast($cartScope);
            }, 'addItem');

            $removeItem = $c->action(function () use ($sessionId, $cartScope, $app): void {
                $id = (int) ($_GET['id'] ?? 0);
                unset(self::$carts[$sessionId][$id]);
                $app->broadcast($cartScope);
            }, 'removeItem');

            $clearCart = $c->action(function () use ($sessionId, $cartScope, $app): void {
                self::$carts[$sessionId] = [];
                $app->broadcast($cartScope);
            }, 'clearCart');

            $c->view(fn (): string => $c->render('examples/shopping_cart.html.twig', [
                'title'       => '🛒 Shopping Cart',
                'description' => 'Add items across browser tabs — SESSION scope persists the cart for every tab in your browser without cookies, localStorage, or Redux.',
                'summary'     => [
                    '<strong>SESSION scope</strong> (via a custom <code>cart:{sessionId}</code> scope) means the cart is shared across every tab in your browser. Open a new tab — the cart is already populated.',
                    '<strong>$app->broadcast($cartScope)</strong> pushes the updated cart to all connected tabs of that session simultaneously. No polling, no cache invalidation, no client state sync.',
                    '<strong>Server-side cart storage</strong> eliminates the client-storage problem entirely. No localStorage. No cookie serialization. The server is the single source of truth.',
                    '<strong>CSS @starting-style</strong> animates newly inserted cart rows without a single line of JavaScript. When Datastar morphs in the new item, the browser\'s entry transition fires automatically.',
                    '<strong>Block partial rendering</strong> — on updates only the #cart-panel fragment is sent, not the product grid. The products are rendered once on initial load and stay static in the DOM.',
                    '<strong>Disconnect cleanup</strong> — onDisconnect removes the cart from memory only when the last tab from the session disconnects, preserving the cart while at least one tab is open.',
                ],
                'anatomy'     => [
                    'signals' => [],
                    'actions' => [
                        ['name' => 'addItem', 'desc' => 'Appends a product (by ?id= param) to the session cart and broadcasts to all session tabs.'],
                        ['name' => 'removeItem', 'desc' => 'Removes a product by ?id= param and broadcasts the updated cart.'],
                        ['name' => 'clearCart', 'desc' => 'Empties the entire cart and broadcasts the empty state.'],
                    ],
                    'views' => [
                        ['name' => 'shopping_cart.html.twig', 'desc' => 'Product grid rendered once on initial load (static). Cart panel re-renders on every broadcast via block: \'cart\'.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/ShoppingCartExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/shopping_cart.html.twig'],
                ],
                'products'    => self::PRODUCTS,
                'cart'        => array_values(self::$carts[$sessionId] ?? []),
                'total'       => self::getTotal($sessionId),
                'addItem'     => $addItem,
                'removeItem'  => $removeItem,
                'clearCart'   => $clearCart,
            ]), block: 'cart', cacheUpdates: false);
        });
    }

    /**
     * @return null|array{id: int, name: string, price: float, emoji: string}
     */
    private static function findProduct(int $id): ?array
    {
        foreach (self::PRODUCTS as $product) {
            if ($product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    private static function getTotal(string $sessionId): float
    {
        $total = 0.0;

        foreach (self::$carts[$sessionId] ?? [] as $item) {
            $total += $item['price'] * $item['qty'];
        }

        return $total;
    }
}
