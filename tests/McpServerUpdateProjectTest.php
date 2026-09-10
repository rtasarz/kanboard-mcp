<?php
declare(strict_types=1);

// RPC wiring tests for update_project (KB#580).
// Run: php tests/McpServerUpdateProjectTest.php

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
    public array $projects = [];
    public array $updated = [];

    public function update(array $values): bool
    {
        $this->updated[] = $values;
        $id = (int) ($values['id'] ?? 0);
        if (!isset($this->projects[$id])) {
            return false;
        }
        foreach ($values as $key => $value) {
            if ($key === 'id') {
                continue;
            }
            $this->projects[$id][$key] = $value;
        }

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
$projects->projects[12] = ['id' => 12, 'name' => 'Old', 'description' => '', 'identifier' => ''];
$server = new McpServer(new ArrayObject(['projectModel' => $projects]));

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$tools = $listResponse['result']['tools'] ?? [];
$toolNames = array_column($tools, 'name');
check(in_array('update_project', $toolNames, true), 'tools/list exposes update_project');

foreach ($tools as $tool) {
    if ($tool['name'] !== 'update_project') {
        continue;
    }
    $schema = $tool['inputSchema'] ?? [];
    check(($schema['required'] ?? []) === ['project_id'], 'update_project requires project_id');
    $properties = $schema['properties'] ?? [];
    check(isset($properties['identifier'], $properties['name'], $properties['description']), 'update_project has identifier, name, description');
    check(!isset($properties['email']), 'update_project does not expose email');
    check(
        str_contains(strtolower((string) ($properties['identifier']['description'] ?? '')), 'alphanumeric'),
        'identifier description says alphanumeric'
    );
}

$res = callTool($server, 'update_project', ['project_id' => 12, 'identifier' => 'lkaebok']);
check($res['isError'] === false && ($res['data']['success'] ?? null) === true, 'update_project succeeds');
check(
    ($projects->updated[0]['identifier'] ?? null) === 'LKAEBOK' && ($projects->projects[12]['identifier'] ?? null) === 'LKAEBOK',
    'update_project stores identifier uppercased'
);

$res = callTool($server, 'update_project', [
    'project_id' => 12,
    'name' => 'Renamed',
    'description' => 'Board desc',
]);
check(
    $res['isError'] === false
        && ($projects->projects[12]['name'] ?? null) === 'Renamed'
        && ($projects->projects[12]['description'] ?? null) === 'Board desc',
    'update_project updates name and description'
);

$res = callTool($server, 'update_project', ['project_id' => 99, 'identifier' => 'X']);
check($res['isError'] === false && ($res['data']['success'] ?? null) === false, 'update_project unknown id returns success false');

$res = callTool($server, 'update_project', ['project_id' => 12, 'identifier' => 'lka-ebok']);
check($res['isError'] === true, 'update_project rejects non-alphanumeric identifier');
$res = callTool($server, 'update_project', ['project_id' => 0, 'identifier' => 'X']);
check($res['isError'] === true, 'update_project rejects non-positive project_id');
$res = callTool($server, 'update_project', ['identifier' => 'X']);
check($res['isError'] === true, 'update_project rejects missing project_id');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
