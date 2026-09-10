<?php
declare(strict_types=1);

// RPC wiring tests for structuredContent on tool success (KB#584).
// Run: php tests/McpServerStructuredContentTest.php

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
    public int $nextId = 10;

    public function getAll(): array
    {
        return $this->projects;
    }

    public function create(array $values): int
    {
        $id = $this->nextId++;
        $this->projects[] = ['id' => $id, 'name' => $values['name'] ?? ''];

        return $id;
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
        return ['isError' => true, 'text' => null, 'data' => null, 'structured' => null, 'raw' => $response];
    }

    return [
        'isError' => $response['result']['isError'] ?? false,
        'text' => $response['result']['content'][0]['text'],
        'data' => json_decode($response['result']['content'][0]['text'], true),
        'structured' => $response['result']['structuredContent'] ?? null,
        'raw' => $response,
    ];
}

$projects = new FakeProjectModel();
$projects->projects = [
    7 => ['id' => 7, 'name' => 'alpha'],
    8 => ['id' => 8, 'name' => 'beta'],
];
$server = new McpServer(new ArrayObject(['projectModel' => $projects]));

$listed = callTool($server, 'get_projects');
check($listed['isError'] === false, 'get_projects succeeds');
check(($listed['data'][0]['id'] ?? null) === 7, 'text blob stays a JSON list');
check(isset($listed['structured']['items']) && is_array($listed['structured']['items']), 'list wrapped as structuredContent.items');
check(($listed['structured']['items'][0]['id'] ?? null) === 7, 'items preserve project rows');
check(!isset($listed['structured'][0]), 'list is not a bare array on structuredContent');

$created = callTool($server, 'create_project', ['name' => 'scratch']);
check($created['isError'] === false && ($created['data']['project_id'] ?? null) === 10, 'text blob stays {project_id}');
check(($created['structured']['project_id'] ?? null) === 10, 'object structuredContent exposes project_id');
check(!isset($created['structured']['items']), 'object is not wrapped in items');

$projects->projects = [];
$empty = callTool($server, 'get_projects');
check(($empty['structured']['items'] ?? null) === [], 'empty list is {items: []}');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
