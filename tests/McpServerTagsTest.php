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

    public function getAllByProject(int $projectId): array
    {
        return $this->byProject[$projectId] ?? [];
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

function buildTagServer(FakeTagModel $tagModel, FakeColorModel $colorModel): McpServer
{
    return new McpServer(new ArrayObject([
        'tagModel' => $tagModel,
        'colorModel' => $colorModel,
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

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
