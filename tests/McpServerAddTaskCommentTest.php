<?php
declare(strict_types=1);

// Run: php tests/McpServerAddTaskCommentTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

final class FakeCommentModel
{
    public array $created = [];

    public function create(array $values): int
    {
        $this->created[] = $values;
        return 10;
    }
}

final class FakeUserSession
{
    public function __construct(private int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}

$checks = 0;
$failures = 0;

function check(bool $condition, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $label\n";
    } else {
        echo "ok:   $label\n";
    }
}

function callTool(McpServer $server, array $arguments = []): array
{
    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'add_task_comment', 'arguments' => $arguments],
    ]);

    if (!isset($response['result']['content'][0]['text']) || isset($response['error'])) {
        return ['isError' => true, 'data' => null];
    }

    return [
        'isError' => $response['result']['isError'] ?? false,
        'data' => json_decode($response['result']['content'][0]['text'], true),
    ];
}

$comments = new FakeCommentModel();
$server = new McpServer(new ArrayObject([
    'commentModel' => $comments,
    'userSession' => new FakeUserSession(1),
]));

$res = callTool($server, ['task_id' => 501, 'comment' => 'hello']);
check($res['isError'] === false && ($res['data']['comment_id'] ?? null) === 10, 'add_task_comment succeeds');
check(($comments->created[0]['user_id'] ?? null) === 1, 'comment user_id is the session user');

$noSession = new FakeCommentModel();
$server = new McpServer(new ArrayObject(['commentModel' => $noSession]));
$res = callTool($server, ['task_id' => 501, 'comment' => 'hello']);
check(($noSession->created[0]['user_id'] ?? null) === 0, 'missing session still stores user_id 0');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
