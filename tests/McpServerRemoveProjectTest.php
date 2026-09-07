<?php
declare(strict_types=1);

// RPC wiring tests for remove_project (KB#479).
// Run: php tests/McpServerRemoveProjectTest.php

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

final class FakeProjectModel
{
    public array $ids = [];
    public array $removed = [];

    public function remove($projectId): bool
    {
        $id = (int) $projectId;
        $this->removed[] = $id;
        if (!in_array($id, $this->ids, true)) {
            return false;
        }
        $this->ids = array_values(array_diff($this->ids, [$id]));

        return true;
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

$projects = new FakeProjectModel();
$projects->ids = [30];
$server = new McpServer(new ArrayObject(['projectModel' => $projects]));

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$tools = $listResponse['result']['tools'] ?? [];
$toolNames = array_column($tools, 'name');
check(in_array('remove_project', $toolNames, true), 'tools/list exposes remove_project');

foreach ($tools as $tool) {
    if ($tool['name'] !== 'remove_project') {
        continue;
    }
    check(($tool['inputSchema']['required'] ?? []) === ['project_id'], 'remove_project requires project_id');
    check(
        str_contains(strtolower($tool['description']), 'explicit'),
        'remove_project description requires explicit user OK'
    );
}

$res = callTool($server, 'remove_project', ['project_id' => 30]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'remove_project succeeds');
check($projects->removed === [30] && !in_array(30, $projects->ids, true), 'remove_project drops the project');

$res = callTool($server, 'remove_project', ['project_id' => 99]);
check($res['isError'] === false && ($res['data']['success'] ?? null) === false, 'remove_project unknown id returns success false');

$res = callTool($server, 'remove_project', ['project_id' => 0]);
check($res['isError'] === true, 'remove_project rejects non-positive project_id');
$res = callTool($server, 'remove_project', []);
check($res['isError'] === true, 'remove_project rejects missing project_id');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
