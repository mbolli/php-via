<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Global shared state
class TodoState {
    /** @var array<int, array{id: int, text: string, completed: bool}> */
    public static array $todos = [
        ['id' => 1, 'text' => 'Buy milk', 'completed' => false],
        ['id' => 2, 'text' => 'Walk dog', 'completed' => false],
        ['id' => 3, 'text' => 'Learn php-via', 'completed' => true],
    ];
    public static int $nextId = 4;
}

// Create configuration
$config = new Config();
$config->withHost('0.0.0.0')
    ->withPort(3000)
    ->withTemplateDir(__DIR__ . '/../templates')
    ->withLogLevel('debug')
;

// Create the application
$app = new Via($config);

$app->page('/', function (Context $c) use ($app): void {
    $newTodo = $c->signal('', 'newTodo');

    $addTodo = $c->routeAction(function (Context $ctx) use ($app): void {
        // Get the signal from the context (each context has its own signal with injected value)
        $newTodoSignal = $ctx->getSignal('newTodo');
        $task = $newTodoSignal ? mb_trim($newTodoSignal->string()) : '';
        if ($task !== '') {
            $app->log('info', "Adding new todo item: {$task}");
            TodoState::$todos[] = ['id' => TodoState::$nextId++, 'text' => $task, 'completed' => false];
            $newTodoSignal?->setValue(''); // Clear the signal value after processing
            $app->broadcast('/');
        } else {
            $app->log('debug', 'Attempted to add empty todo item, ignoring.' . $newTodoSignal?->id());
        }
    }, 'addTodo');

    $deleteTodo = $c->routeAction(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        TodoState::$todos = array_filter(TodoState::$todos, fn (array $todo) => $todo['id'] !== $id);
        TodoState::$todos = array_values(TodoState::$todos); // Re-index array
        $app->broadcast('/');
    }, 'deleteTodo');

    $toggleTodo = $c->routeAction(function (Context $ctx) use ($app): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        foreach (TodoState::$todos as $key => $todo) {
            if ($todo['id'] === $id) {
                TodoState::$todos[$key]['completed'] = !TodoState::$todos[$key]['completed'];

                break;
            }
        }
        $app->broadcast('/');
    }, 'toggleTodo');

    $c->view(function (bool $isUpdate = false) use ($c, $newTodo, $addTodo, $deleteTodo, $toggleTodo): string {
        $block = $isUpdate ? 'content' : null;

        return $c->render('todo.html.twig', [
            'todos' => TodoState::$todos,
            'newTodo' => $newTodo,
            'addTodo' => $addTodo,
            'deleteTodo' => $deleteTodo,
            'toggleTodo' => $toggleTodo,
        ], $block);
    });
});

echo "Starting Via Todo List on http://0.0.0.0:3000\n";
$app->start();
