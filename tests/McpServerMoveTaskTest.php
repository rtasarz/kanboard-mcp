<?php
declare(strict_types=1);

// RPC wiring tests for the move_task tool and the only_open toggle (KB#484).
// Mocks the Kanboard container models and drives McpServer::handleRequest;
// never touches a live Kanboard instance.
//
// Run: php tests/McpServerMoveTaskTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

// --- fakes -----------------------------------------------------------------

final class FakeTaskFinderModel
{
    public array $tasks = [];

    public function getById(int $taskId): array
    {
        return $this->tasks[$taskId] ?? [];
    }
}

final class FakeTaskPositionModel
{
    public ?array $lastCall = null;
    public bool $result = true;

    public function movePosition(...$arguments): bool
    {
        $this->lastCall = $arguments;
        return $this->result;
    }
}

// --- harness ---------------------------------------------------------------

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

function callTool(McpServer $server, string $name, array $arguments = []): array
{
    $response = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => $arguments],
    ]);

    if (!isset($response['result']['content'][0]['text']) || isset($response['error'])) {
        return ['isError' => true, 'text' => null, 'data' => null];
    }

    return [
        'isError' => $response['result']['isError'] ?? false,
        'text' => $response['result']['content'][0]['text'],
        'data' => json_decode($response['result']['content'][0]['text'], true),
    ];
}

// --- fixtures ---------------------------------------------------------------

$finder = new FakeTaskFinderModel();
$finder->tasks[10] = ['id' => 10, 'is_active' => 1, 'column_id' => 20, 'swimlane_id' => 19];
$finder->tasks[11] = ['id' => 11, 'is_active' => 0, 'column_id' => 20, 'swimlane_id' => 19];

$position = new FakeTaskPositionModel();

$server = new McpServer(new ArrayObject([
    'taskFinderModel' => $finder,
    'taskPositionModel' => $position,
]));

// --- tests -----------------------------------------------------------------

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$moveTool = null;
foreach ($listResponse['result']['tools'] ?? [] as $tool) {
    if ($tool['name'] === 'move_task') {
        $moveTool = $tool;
    }
}
check($moveTool !== null, 'tools/list exposes move_task');
check(($moveTool['inputSchema']['properties']['only_open']['type'] ?? null) === 'boolean', 'move_task schema declares only_open as boolean');
check(!in_array('only_open', $moveTool['inputSchema']['required'] ?? [], true), 'only_open is optional');

$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 10, 'column_id' => 20]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'default move of open task succeeds');
check($position->lastCall === [1, 10, 20, 1, 0, true, true], 'default call keeps onlyOpen=true (core default parity)');

$position->lastCall = null;
$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 10, 'column_id' => 20, 'swimlane_id' => 19]);
check($res['data']['success'] === true && $position->lastCall === [1, 10, 20, 1, 19, true, true], 'swimlane_id still passes through');

$position->lastCall = null;
$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 11, 'column_id' => 21, 'only_open' => false]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'only_open=false moves closed task');
check($position->lastCall === [1, 11, 21, 1, 0, true, false], 'only_open=false reaches core as onlyOpen=false');

$position->lastCall = null;
$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 11, 'column_id' => 21]);
check(($res['data']['success'] ?? null) === false, 'closed task with only_open default reports success=false');
check(isset($res['data']['message']) && str_contains((string) $res['data']['message'], 'only_open'), 'refusal message names only_open');
check($position->lastCall === null, 'blocked move never reaches TaskPositionModel');

$position->result = false;
$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 10, 'column_id' => 20]);
check(($res['data']['success'] ?? null) === false, 'core failure surfaces as success=false');
$position->result = true;

$res = callTool($server, 'move_task', ['project_id' => 1, 'task_id' => 10]);
check($res['isError'] === true, 'move_task rejects missing column_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
