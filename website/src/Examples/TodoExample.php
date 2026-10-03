<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Attributes\Action;
use Mbolli\PhpVia\Attributes\Broadcast;
use Mbolli\PhpVia\Attributes\Signal;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * Composition API version of the multiplayer todo list.
 *
 * #[Broadcast(Scope::ROUTE)] makes ROUTE the context's primary scope, so a bare
 * $ctx->broadcast() fans out to every browser on this route. The draft input is a
 * TAB-scoped #[Signal], private per tab. The shared list lives in a static array
 * (plain shared state, no attribute needed); each action mutates it and broadcasts.
 *
 * The view's data is a closure, so the static $todos array is re-read on every render
 * (an array would freeze the data captured at setup time).
 */
#[Broadcast(Scope::ROUTE)]
final class TodoExample {
    /** Draft input text, TAB-scoped so your typing doesn't leak to other viewers. */
    #[Signal]
    public string $newTodo = '';

    /** @var array<int, array{id: int, text: string, completed: bool}> */
    private static array $todos = [
        ['id' => 1, 'text' => 'Buy milk', 'completed' => false],
        ['id' => 2, 'text' => 'Walk dog', 'completed' => false],
        ['id' => 3, 'text' => 'Learn php-via', 'completed' => true],
    ];

    private static int $nextId = 4;

    public function view(Context $ctx): void {
        $ctx->view('examples/todo.html.twig', fn (): array => [
            'title' => '✓ Todo List',
            'description' => 'Composition API: a static shared list + a TAB-scoped <code>#[Signal]</code> draft. <code>#[Broadcast(Scope::ROUTE)]</code> makes every action fan out to all viewers.',
            'summary' => [
                '<strong>#[Broadcast(Scope::ROUTE)]</strong> on the class sets ROUTE as the primary scope. A bare <code>$ctx->broadcast()</code> then re-renders the list for every browser on this route. No scope argument needed.',
                '<strong>Mixed state</strong>: the todo list is a plain <code>static</code> array (shared across the worker, no attribute required), while the input is a TAB-scoped <code>#[Signal]</code> so your draft stays private.',
                '<strong>#[Action] methods</strong> mutate the static array, then call <code>$ctx->broadcast()</code>. Adding clears the draft via <code>$this->newTodo = \'\'</code>, which syncs back to the input automatically.',
                '<strong>Data closure</strong>: the view passes its data as a closure, so <code>self::$todos</code> is re-read on every render. An array would freeze the data captured at setup.',
                '<strong>One render per tab</strong> (the default), since each tab shows its own draft. <code>block: \'demo\'</code> sends only the block with the <code>#todo-list</code> on updates, which keeps SSE payloads small.',
            ],
            'anatomy' => [
                'signals' => [
                    ['name' => 'newTodo', 'type' => 'string', 'scope' => 'TAB', 'default' => '""', 'desc' => '#[Signal] draft input, private per tab so your typing doesn\'t leak to others.'],
                ],
                'actions' => [
                    ['name' => 'addTodo', 'desc' => 'Appends the trimmed draft to the static list, clears it, and broadcasts to all viewers.'],
                    ['name' => 'deleteTodo', 'desc' => 'Removes a todo by ID (from $ctx->input(\'id\')) and broadcasts the updated list.'],
                    ['name' => 'toggleTodo', 'desc' => 'Flips the completed state of a todo and broadcasts.'],
                ],
                'views' => [
                    ['name' => 'todo.html.twig', 'desc' => 'Renders only the demo block, the #todo-list, on updates.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View page class', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/TodoExample.php'],
                ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/todo.html.twig'],
            ],
            'todos' => self::$todos,
        ], block: 'demo');
    }

    #[Action]
    public function addTodo(Context $ctx): void {
        $task = mb_trim($this->newTodo);
        if ($task !== '') {
            self::$todos[] = ['id' => self::$nextId++, 'text' => $task, 'completed' => false];
            $this->newTodo = '';
            $ctx->broadcast();
        }
    }

    #[Action]
    public function deleteTodo(Context $ctx): void {
        $id = (int) $ctx->input('id', 0);
        self::$todos = array_values(array_filter(self::$todos, fn (array $todo) => $todo['id'] !== $id));
        $ctx->broadcast();
    }

    #[Action]
    public function toggleTodo(Context $ctx): void {
        $id = (int) $ctx->input('id', 0);
        foreach (self::$todos as $key => $todo) {
            if ($todo['id'] === $id) {
                self::$todos[$key]['completed'] = !self::$todos[$key]['completed'];

                break;
            }
        }
        $ctx->broadcast();
    }

    public static function register(Via $app): void {
        $app->mount(self::class, '/examples/todo');
    }
}
