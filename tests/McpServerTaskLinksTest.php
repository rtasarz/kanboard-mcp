<?php
declare(strict_types=1);

// RPC wiring tests for internal task links on get_task_details (KB#536 iter 1).
// Mocks the Kanboard container models and drives McpServer::handleRequest.
// Run: php tests/McpServerTaskLinksTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

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

final class FakeTaskFinderModel
{
    public array $byId = [];
    public array $all = [];

    public function getById($taskId)
    {
        return $this->byId[(int) $taskId] ?? null;
    }

    public function getDetails($taskId)
    {
        $task = $this->getById($taskId);
        if (!is_array($task)) {
            return $task;
        }

        return $task + ['project_name' => 'clacks'];
    }

    public function getAll($projectId, $statusId): array
    {
        return $this->all[(int) $projectId][(int) $statusId] ?? [];
    }
}

final class FakeTaskTagModel
{
    public function getTagsByTask($taskId): array
    {
        return [];
    }

    public function getTagsByTaskIds($taskIds): array
    {
        return [];
    }
}

final class FakeTaskLinkModel
{
    public array $byTask = [];

    public function getAll($taskId): array
    {
        return $this->byTask[(int) $taskId] ?? [];
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

$finder = new FakeTaskFinderModel();
$finder->byId[10] = ['id' => 10, 'title' => 'Parent', 'project_id' => 2];
$finder->byId[11] = ['id' => 11, 'title' => 'Child', 'project_id' => 2];
$finder->all[2][1] = [$finder->byId[10], $finder->byId[11]];

$links = new FakeTaskLinkModel();
$links->byTask[10] = [
    [
        'id' => 7,
        'task_id' => 11,
        'label' => 'is a parent of',
        'title' => 'Child',
        'project_id' => 2,
        'is_active' => 1,
    ],
];

$server = new McpServer(new ArrayObject([
    'taskFinderModel' => $finder,
    'taskTagModel' => new FakeTaskTagModel(),
    'taskLinkModel' => $links,
]));

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$toolNames = array_column($listResponse['result']['tools'] ?? [], 'name');
check(!in_array('get_task_links', $toolNames, true), 'tools/list does not expose get_task_links');

$res = callTool($server, 'get_task_details', ['task_id' => 10]);
check($res['isError'] === false, 'get_task_details succeeds');
$expected = [
    [
        'id' => 7,
        'opposite_task_id' => 11,
        'label' => 'is a parent of',
        'title' => 'Child',
        'project_id' => 2,
    ],
];
check(($res['data']['links'] ?? null) === $expected, 'get_task_details remaps Kanboard task_id alias to opposite_task_id');
check(!isset($res['data']['links'][0]['task_id']), 'get_task_details link rows omit task_id');
check(!isset($res['data']['links'][0]['is_active']), 'get_task_details link rows drop extra Kanboard columns');

$res = callTool($server, 'get_task_details', ['task_id' => 10, 'verbose' => true]);
check(($res['data']['project_name'] ?? null) === 'clacks' && ($res['data']['links'] ?? null) === $expected, 'get_task_details verbose still includes links');

$res = callTool($server, 'get_task_details', ['task_id' => 11]);
check(($res['data']['links'] ?? null) === [], 'get_task_details with no links returns links: []');

$res = callTool($server, 'get_tasks', ['project_id' => 2]);
check($res['isError'] === false && count($res['data'] ?? []) === 2, 'get_tasks returns both tasks');
check(!array_key_exists('links', $res['data'][0] ?? []), 'get_tasks does not attach links');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
