<?php
declare(strict_types=1);

// RPC wiring tests for tag / color tools (KB#468 iter 1).
// Mocks the Kanboard container models and drives McpServer::handleRequest;
// never touches a live Kanboard instance.
//
// Run: php tests/McpServerTagsTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpServer;

require __DIR__ . '/bootstrap.php';

final class FakeTagModel
{
    public array $byProject = [];
    public array $byId = [];
    public int $nextId = 100;

    public function getAllByProject(int $projectId): array
    {
        return $this->byProject[$projectId] ?? [];
    }

    public function getById($tagId)
    {
        return $this->byId[(int) $tagId] ?? null;
    }

    public function getIdByName($projectId, $tag)
    {
        foreach ($this->byId as $row) {
            if ((int) $row['project_id'] === (int) $projectId && strcasecmp((string) $row['name'], (string) $tag) === 0) {
                return $row['id'];
            }
        }

        return 0;
    }

    public function create($projectId, $tag, $colorId = null)
    {
        $id = $this->nextId++;
        $row = [
            'id' => $id,
            'name' => $tag,
            'color_id' => $colorId,
            'project_id' => (int) $projectId,
        ];
        $this->byId[$id] = $row;
        $this->byProject[(int) $projectId][] = $row;

        return $id;
    }

    public function update($tagId, $tag, $colorId = null, $projectId = null): bool
    {
        if (!isset($this->byId[(int) $tagId])) {
            return false;
        }

        $this->byId[(int) $tagId]['name'] = $tag;
        $this->byId[(int) $tagId]['color_id'] = $colorId;
        if ($projectId !== null) {
            $this->byId[(int) $tagId]['project_id'] = (int) $projectId;
        }

        return true;
    }

    public function remove($tagId): bool
    {
        unset($this->byId[(int) $tagId]);

        return true;
    }
}

final class FakeTaskFinderModel
{
    public array $projectByTask = [];
    public array $byId = [];
    public array $all = [];

    public function getProjectId($taskId): int
    {
        return $this->projectByTask[(int) $taskId] ?? 0;
    }

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
    public array $saved = [];
    public array $byTask = [];

    public function save($projectId, $taskId, array $tags, $removeOtherTags = true): bool
    {
        $this->saved[] = [
            'project_id' => (int) $projectId,
            'task_id' => (int) $taskId,
            'tags' => array_values($tags),
            'replace' => (bool) $removeOtherTags,
        ];

        return true;
    }

    public function getTagsByTask($taskId): array
    {
        return $this->byTask[(int) $taskId] ?? [];
    }

    public function getTagsByTaskIds($taskIds): array
    {
        $out = [];
        foreach ($taskIds as $id) {
            $id = (int) $id;
            if (!isset($this->byTask[$id])) {
                continue;
            }
            $out[$id] = [];
            foreach ($this->byTask[$id] as $row) {
                $out[$id][] = $row + ['task_id' => $id];
            }
        }

        return $out;
    }
}

final class FakeColorModel
{
    public array $list = [];

    public function getList($prepend = false): array
    {
        return $this->list;
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

function buildTagServer(
    FakeTagModel $tagModel,
    FakeColorModel $colorModel,
    ?FakeTaskFinderModel $taskFinder = null,
    ?FakeTaskTagModel $taskTag = null
): McpServer {
    return new McpServer(new ArrayObject([
        'tagModel' => $tagModel,
        'colorModel' => $colorModel,
        'taskFinderModel' => $taskFinder ?? new FakeTaskFinderModel(),
        'taskTagModel' => $taskTag ?? new FakeTaskTagModel(),
    ]));
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

$tagModel = new FakeTagModel();
$tagModel->byProject[2] = [
    ['id' => 1, 'name' => 'core', 'color_id' => 'yellow', 'project_id' => 2],
    ['id' => 6, 'name' => 'dev', 'color_id' => null, 'project_id' => 2],
];
foreach ($tagModel->byProject[2] as $row) {
    $tagModel->byId[$row['id']] = $row;
}

$colorModel = new FakeColorModel();
$colorModel->list = ['yellow' => 'Yellow', 'blue' => 'Blue', 'deep_orange' => 'Deep Orange'];

$server = buildTagServer($tagModel, $colorModel);

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$toolNames = array_column($listResponse['result']['tools'] ?? [], 'name');
check(in_array('get_project_tags', $toolNames, true), 'tools/list exposes get_project_tags');
check(in_array('get_colors', $toolNames, true), 'tools/list exposes get_colors');
check(!in_array('get_task_tags', $toolNames, true), 'tools/list does not expose get_task_tags');

foreach ($listResponse['result']['tools'] as $tool) {
    if ($tool['name'] === 'get_project_tags') {
        check(($tool['inputSchema']['required'] ?? []) === ['project_id'], 'get_project_tags requires project_id');
    }
    if ($tool['name'] === 'get_colors') {
        check(empty($tool['inputSchema']['required'] ?? []), 'get_colors takes no arguments');
    }
}

$res = callTool($server, 'get_project_tags', ['project_id' => 2]);
check($res['isError'] === false, 'get_project_tags succeeds');
check($res['data'] === $tagModel->byProject[2], 'get_project_tags returns tag rows (id, name, color_id, project_id)');

$res = callTool($server, 'get_project_tags', ['project_id' => 99]);
check($res['isError'] === false && $res['data'] === [], 'get_project_tags on project without tags returns []');

$res = callTool($server, 'get_project_tags', ['project_id' => -1]);
check($res['isError'] === true, 'get_project_tags rejects non-positive project_id');
$res = callTool($server, 'get_project_tags', []);
check($res['isError'] === true, 'get_project_tags rejects missing project_id');

$res = callTool($server, 'get_colors');
check($res['isError'] === false, 'get_colors succeeds');
check($res['data'] === $colorModel->list, 'get_colors returns color_id => name map');

check(in_array('create_tag', $toolNames, true), 'tools/list exposes create_tag');
check(in_array('update_tag', $toolNames, true), 'tools/list exposes update_tag');
check(in_array('remove_tag', $toolNames, true), 'tools/list exposes remove_tag');
check(in_array('set_task_tags', $toolNames, true), 'tools/list exposes set_task_tags');

$res = callTool($server, 'create_tag', ['project_id' => 2, 'name' => 'auth-oauth', 'color_id' => 'blue']);
check($res['isError'] === false && ($res['data']['tag_id'] ?? null) === 100, 'create_tag returns tag_id');
check(($tagModel->byId[100]['color_id'] ?? null) === 'blue', 'create_tag persists color_id');

$res = callTool($server, 'create_tag', ['project_id' => 2, 'name' => '  ']);
check($res['isError'] === true, 'create_tag rejects blank name');

$res = callTool($server, 'update_tag', ['tag_id' => 100, 'color_id' => 'green']);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'update_tag color-only succeeds');
check(($tagModel->byId[100]['name'] ?? null) === 'auth-oauth' && ($tagModel->byId[100]['color_id'] ?? null) === 'green', 'update_tag keeps name when only color_id set');

$res = callTool($server, 'update_tag', ['tag_id' => 999, 'name' => 'nope']);
check($res['isError'] === true, 'update_tag rejects unknown tag_id');

$res = callTool($server, 'remove_tag', ['tag_id' => 100]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'remove_tag succeeds');
check(!isset($tagModel->byId[100]), 'remove_tag drops the tag');

$res = callTool($server, 'remove_tag', ['tag_id' => 0]);
check($res['isError'] === true, 'remove_tag rejects non-positive tag_id');

$taskFinder = new FakeTaskFinderModel();
$taskFinder->projectByTask[50] = 2;
$taskTag = new FakeTaskTagModel();
$server = buildTagServer($tagModel, $colorModel, $taskFinder, $taskTag);

$res = callTool($server, 'set_task_tags', ['task_id' => 50, 'tags' => ['core', 'fresh']]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'set_task_tags succeeds');
check(($res['data']['new_tags'] ?? null) === [['id' => 101, 'name' => 'fresh']], 'set_task_tags returns only newly created tags as {id, name}');
check(($taskTag->saved[0]['replace'] ?? true) === false, 'set_task_tags replace defaults to false');
check($taskTag->saved[0]['tags'] === ['core', 'fresh'], 'set_task_tags saves names');

$res = callTool($server, 'set_task_tags', ['task_id' => 50, 'tags' => ['core'], 'replace' => true]);
check($res['isError'] === false && ($taskTag->saved[1]['replace'] ?? false) === true, 'set_task_tags replace=true is passed through');
check(($res['data']['new_tags'] ?? null) === [], 'set_task_tags new_tags is [] when all names exist');

$res = callTool($server, 'set_task_tags', ['task_id' => 99, 'tags' => ['core']]);
check($res['isError'] === true, 'set_task_tags rejects unknown task_id');

$res = callTool($server, 'set_task_tags', ['task_id' => 50, 'tags' => 'core']);
check($res['isError'] === true, 'set_task_tags rejects non-array tags');

$taskFinder->byId[50] = ['id' => 50, 'title' => 'Tagged', 'project_id' => 2];
$taskFinder->byId[51] = ['id' => 51, 'title' => 'Bare', 'project_id' => 2];
$taskFinder->all[2][1] = [$taskFinder->byId[50], $taskFinder->byId[51]];
$taskTag->byTask[50] = [
    ['id' => 1, 'name' => 'core', 'color_id' => 'yellow'],
    ['id' => 6, 'name' => 'dev', 'color_id' => null],
];

$res = callTool($server, 'get_task_details', ['task_id' => 50]);
check($res['isError'] === false, 'get_task_details succeeds');
check(($res['data']['tags'] ?? null) === $taskTag->byTask[50], 'get_task_details includes tags as {id, name, color_id}');

$res = callTool($server, 'get_task_details', ['task_id' => 50, 'verbose' => true]);
check(($res['data']['project_name'] ?? null) === 'clacks' && ($res['data']['tags'] ?? null) === $taskTag->byTask[50], 'get_task_details verbose still includes tags');

$res = callTool($server, 'get_task_details', ['task_id' => 51]);
check(($res['data']['tags'] ?? null) === [], 'get_task_details with no tags returns tags: []');

$res = callTool($server, 'get_tasks', ['project_id' => 2]);
check($res['isError'] === false && count($res['data'] ?? []) === 2, 'get_tasks returns both tasks');
check(($res['data'][0]['tags'] ?? null) === $taskTag->byTask[50], 'get_tasks attaches tags to tagged task');
check(($res['data'][1]['tags'] ?? null) === [], 'get_tasks attaches tags: [] to untagged task');
check(!isset($res['data'][0]['tags'][0]['task_id']), 'get_tasks tag rows omit task_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
