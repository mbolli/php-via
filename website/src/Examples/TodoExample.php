<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class TodoExample {
    public const string SLUG = 'todo';

    /** @var array<int, array{id: int, text: string, completed: bool}> */
    private static array $todos = [
        ['id' => 1, 'text' => 'Buy milk', 'completed' => false],
        ['id' => 2, 'text' => 'Walk dog', 'completed' => false],
        ['id' => 3, 'text' => 'Learn php-via', 'completed' => true],
    ];

    private static int $nextId = 4;

    public static function register(Via $app): void {
        $app->page('/examples/todo', function (Context $c) use ($app): void {
            $c->scope(Scope::ROUTE);

            $c->signal('', 'newTodo', Scope::TAB);

            $c->action(function (Context $ctx) use ($app): void {
                $newTodo = $ctx->getSignal('newTodo');
                $task = $newTodo ? mb_trim($newTodo->string()) : '';
                if ($task !== '') {
                    self::$todos[] = ['id' => self::$nextId++, 'text' => $task, 'completed' => false];
                    $newTodo->setValue('');
                    $app->broadcast(Scope::ROUTE);
                }
            }, 'addTodo');

            $c->action(function (Context $ctx) use ($app): void {
                $id = (int) $ctx->input('id', 0);
                self::$todos = array_values(array_filter(self::$todos, fn (array $todo) => $todo['id'] !== $id));
                $app->broadcast(Scope::ROUTE);
            }, 'deleteTodo');

            $c->action(function (Context $ctx) use ($app): void {
                $id = (int) $ctx->input('id', 0);
                foreach (self::$todos as $key => $todo) {
                    if ($todo['id'] === $id) {
                        self::$todos[$key]['completed'] = !self::$todos[$key]['completed'];

                        break;
                    }
                }
                $app->broadcast(Scope::ROUTE);
            }, 'toggleTodo');

            $c->view(fn (): string => $c->render('examples/todo.html.twig', [
                'title' => '✓ Todo List',
                'description' => 'Multiplayer todo app. One shared list for all connected clients — ROUTE scope.',
                'summary' => [
                    '<strong>ROUTE scope</strong> means every browser tab visiting this URL shares the same todo list. Add an item in one tab and it appears in all others instantly.',
                    '<strong>Mixed scopes</strong> on the same page: the todo list is shared (ROUTE), but the input field is private (TAB) — your draft doesn\'t leak to others.',
                    '<strong>Broadcast</strong> pushes updates to all connected clients in the scope. After adding, deleting, or toggling a todo, everyone\'s view re-renders.',
                    '<strong>cacheUpdates: false</strong> disables view caching so every broadcast re-renders the full list. This is intentional — the todo array changes on every action.',
                    '<strong>Partial rendering</strong> — on updates only the <code>#todo-list</code> fragment is sent, not the entire page shell. This keeps SSE payloads small and fast.',
                    '<strong>In-memory storage</strong> keeps it simple for a demo. In production you\'d swap the static array for a database query inside the view function.',
                ],
                'anatomy' => [
                    'signals' => [
                        ['name' => 'newTodo', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => 'Draft input text — private per tab so your typing doesn\'t leak to others.'],
                    ],
                    'actions' => [
                        ['name' => 'addTodo', 'desc' => 'Appends the draft to the shared list and broadcasts to all viewers.'],
                        ['name' => 'deleteTodo', 'desc' => 'Removes a todo by ID and broadcasts the updated list.'],
                        ['name' => 'toggleTodo', 'desc' => 'Flips the completed state of a todo and broadcasts.'],
                    ],
                    'views' => [
                        ['name' => 'todo.html.twig', 'desc' => 'ROUTE-scoped shared list with TAB-scoped input. Partial rendering sends only the #todo-list block on updates.'],
                    ],
                ],
                'githubLinks' => [
                    ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/TodoExample.php'],
                    ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/todo.html.twig'],
                ],
                'todos' => self::$todos,
            ]), block: 'demo', cacheUpdates: false);
        });
    }
}
