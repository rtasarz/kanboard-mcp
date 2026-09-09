<?php
declare(strict_types=1);

// RPC wiring tests for internal task links (KB#536).
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
    public array $created = [];
    public array $removed = [];
    public int $nextId = 20;
    public bool $createFails = false;

    public function getAll($taskId): array
    {
        return $this->byTask[(int) $taskId] ?? [];
    }

    public function create($taskId, $oppositeTaskId, $linkId)
    {
        if ($this->createFails) {
            return false;
        }

        $id = $this->nextId++;
        $this->created[] = [
            'id' => $id,
            'task_id' => (int) $taskId,
            'opposite_task_id' => (int) $oppositeTaskId,
            'link_id' => (int) $linkId,
        ];

        return $id;
    }

    public function remove($taskLinkId): bool
    {
        $this->removed[] = (int) $taskLinkId;

        return (int) $taskLinkId > 0;
    }
}

final class FakeLinkModel
{
    public array $byLabel = [];

    public function getByLabel($label)
    {
        return $this->byLabel[(string) $label] ?? null;
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
check(in_array('create_task_link', $toolNames, true), 'tools/list exposes create_task_link');
check(in_array('remove_task_link', $toolNames, true), 'tools/list exposes remove_task_link');
check(!in_array('update_task_link', $toolNames, true), 'tools/list does not expose update_task_link');

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

$linkModel = new FakeLinkModel();
$linkModel->byLabel['is a parent of'] = ['id' => 7, 'label' => 'is a parent of'];
$server = new McpServer(new ArrayObject([
    'taskFinderModel' => $finder,
    'taskTagModel' => new FakeTaskTagModel(),
    'taskLinkModel' => $links,
    'linkModel' => $linkModel,
]));

$res = callTool($server, 'create_task_link', [
    'task_id' => 10,
    'opposite_task_id' => 11,
    'label' => 'is a parent of',
]);
check($res['isError'] === false && ($res['data']['task_link_id'] ?? null) === 20, 'create_task_link returns task_link_id');
check(($links->created[0] ?? null) === [
    'id' => 20,
    'task_id' => 10,
    'opposite_task_id' => 11,
    'link_id' => 7,
], 'create_task_link resolves label via linkModel');

$res = callTool($server, 'create_task_link', [
    'task_id' => 10,
    'opposite_task_id' => 11,
    'label' => 'nope',
]);
check($res['isError'] === true, 'create_task_link rejects unknown label');

$res = callTool($server, 'create_task_link', ['task_id' => 10, 'opposite_task_id' => 11, 'label' => '  ']);
check($res['isError'] === true, 'create_task_link rejects blank label');

$res = callTool($server, 'create_task_link', ['task_id' => 0, 'opposite_task_id' => 11, 'label' => 'is a parent of']);
check($res['isError'] === true, 'create_task_link rejects non-positive task_id');

$links->createFails = true;
$res = callTool($server, 'create_task_link', [
    'task_id' => 10,
    'opposite_task_id' => 11,
    'label' => 'is a parent of',
]);
check($res['isError'] === true, 'create_task_link surfaces model failure');

$res = callTool($server, 'remove_task_link', ['task_link_id' => 7]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'remove_task_link succeeds');
check($links->removed === [7], 'remove_task_link passes task_link_id');

$res = callTool($server, 'remove_task_link', ['task_link_id' => 0]);
check($res['isError'] === true, 'remove_task_link rejects non-positive task_link_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
