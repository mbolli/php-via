<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Global shared state
class TodoState
{
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
    ->withDocumentTitle('⚡ Via Todo List')
    ->withTemplateDir(__DIR__ . '/../templates');

// Create the application
$app = new Via($config);

$app->page('/', function (Context $c): void {
    $newTodo = $c->signal('', 'newTodo');
    $addTodo = $c->action(function () use ($newTodo, $c): void {
        $task = mb_trim($newTodo->string());
        if ($task !== '') {
            TodoState::$todos[] = ['id' => TodoState::$nextId++, 'text' => $task, 'completed' => false];
            $newTodo->setValue('');
            $c->getApp()->broadcast('/');
        }
    }, 'addTodo');

    $deleteTodo = $c->action(function () use ($c): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        TodoState::$todos = array_filter(TodoState::$todos, fn($todo) => $todo['id'] !== $id);
        TodoState::$todos = array_values(TodoState::$todos); // Re-index array
        $c->getApp()->broadcast('/');
    }, 'deleteTodo');

    $toggleTodo = $c->action(function () use ($c): void {
        $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
        foreach (TodoState::$todos as $key => $todo) {
            if ($todo['id'] === $id) {
                TodoState::$todos[$key]['completed'] = !TodoState::$todos[$key]['completed'];
                break;
            }
        }
        $c->getApp()->broadcast('/');
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
