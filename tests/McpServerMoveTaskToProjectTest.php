<?php
declare(strict_types=1);

// RPC wiring tests for move_task_to_project (KB#596).
// Mocks the Kanboard container models and drives McpServer::handleRequest;
// never touches a live Kanboard instance.
//
// Run: php tests/McpServerMoveTaskToProjectTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

// --- fakes -----------------------------------------------------------------

final class FakeTaskProjectMoveModel
{
    public ?array $lastCall = null;
    public bool $result = true;

    public function moveToProject(...$arguments): bool
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

$mover = new FakeTaskProjectMoveModel();
$server = new McpServer(new ArrayObject([
    'taskProjectMoveModel' => $mover,
]));

// --- tests -----------------------------------------------------------------

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$tool = null;
foreach ($listResponse['result']['tools'] ?? [] as $listed) {
    if ($listed['name'] === 'move_task_to_project') {
        $tool = $listed;
    }
}
check($tool !== null, 'tools/list exposes move_task_to_project');
check(($tool['inputSchema']['required'] ?? []) === ['task_id', 'project_id'], 'required fields are task_id and project_id');
foreach (['swimlane_id', 'column_id', 'category_id', 'owner_id'] as $optional) {
    check(($tool['inputSchema']['properties'][$optional]['type'] ?? null) === 'integer', "$optional is optional integer");
    check(!in_array($optional, $tool['inputSchema']['required'] ?? [], true), "$optional is not required");
}

$res = callTool($server, 'move_task_to_project', ['task_id' => 10, 'project_id' => 2]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'default move succeeds');
check($mover->lastCall === [10, 2, null, null, null, null], 'omitted destination fields pass as null');

$mover->lastCall = null;
$res = callTool($server, 'move_task_to_project', [
    'task_id' => 10,
    'project_id' => 2,
    'swimlane_id' => 5,
    'column_id' => 7,
    'category_id' => 3,
    'owner_id' => 8,
]);
check($res['data']['success'] === true && $mover->lastCall === [10, 2, 5, 7, 3, 8], 'optional ids pass through');

$mover->lastCall = null;
$res = callTool($server, 'move_task_to_project', ['task_id' => 10, 'project_id' => 2, 'owner_id' => 0]);
check($res['data']['success'] === true && $mover->lastCall === [10, 2, null, null, null, 0], 'owner_id 0 passes through');

$mover->result = false;
$res = callTool($server, 'move_task_to_project', ['task_id' => 10, 'project_id' => 2]);
check(($res['data']['success'] ?? null) === false, 'core failure surfaces as success=false');
$mover->result = true;

$mover->lastCall = null;
$res = callTool($server, 'move_task_to_project', ['task_id' => 0, 'project_id' => 2]);
check($res['isError'] === true, 'rejects non-positive task_id');
check($mover->lastCall === null, 'rejected task_id never reaches model');

$res = callTool($server, 'move_task_to_project', ['task_id' => 10, 'project_id' => 0]);
check($res['isError'] === true, 'rejects non-positive project_id');

$res = callTool($server, 'move_task_to_project', ['task_id' => 10]);
check($res['isError'] === true, 'rejects missing project_id');

$res = callTool($server, 'move_task_to_project', ['project_id' => 2]);
check($res['isError'] === true, 'rejects missing task_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
