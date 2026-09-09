<?php
declare(strict_types=1);

// RPC wiring tests for search_tasks (KB#557).
// Mocks the Kanboard container models and drives McpServer::handleRequest.
// Run: php tests/McpServerSearchTasksTest.php

use Kanboard\Filter\TaskProjectFilter;
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

final class FakeTaskLexer
{
    public array $queries = [];
    public array $filters = [];
    public array $results = [];

    public function build($query): self
    {
        $this->queries[] = $query;

        return $this;
    }

    public function withFilter($filter): self
    {
        $this->filters[] = $filter;

        return $this;
    }

    public function toArray(): array
    {
        return $this->results;
    }
}

final class FakeTaskTagModel
{
    public array $byTask = [];

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

$lexer = new FakeTaskLexer();
$lexer->results = [
    ['id' => 50, 'title' => 'Tagged', 'project_id' => 2],
    ['id' => 51, 'title' => 'Bare', 'project_id' => 2],
];
$taskTag = new FakeTaskTagModel();
$taskTag->byTask[50] = [
    ['id' => 1, 'name' => 'core', 'color_id' => 'yellow'],
    ['id' => 6, 'name' => 'dev', 'color_id' => null],
];
$taskLink = new FakeTaskLinkModel();
$taskLink->byTask[50] = [
    [
        'id' => 7,
        'task_id' => 51,
        'label' => 'is a parent of',
        'title' => 'Bare',
        'project_id' => 2,
        'is_active' => 1,
    ],
];

$server = new McpServer(new ArrayObject([
    'taskLexer' => $lexer,
    'taskTagModel' => $taskTag,
    'taskLinkModel' => $taskLink,
]));

$listResponse = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/list',
    'params' => [],
]);
$toolNames = array_column($listResponse['result']['tools'] ?? [], 'name');
check(in_array('search_tasks', $toolNames, true), 'tools/list exposes search_tasks');
check(!in_array('get_tasks', $toolNames, true), 'tools/list does not expose get_tasks');

foreach ($listResponse['result']['tools'] as $tool) {
    if ($tool['name'] === 'search_tasks') {
        check(($tool['inputSchema']['required'] ?? []) === ['project_id', 'query'], 'search_tasks requires project_id and query');
        check(isset($tool['inputSchema']['properties']['include_links']), 'search_tasks declares include_links');
    }
}

$res = callTool($server, 'search_tasks', ['project_id' => 2, 'query' => 'status:open tag:core']);
check($res['isError'] === false && count($res['data'] ?? []) === 2, 'search_tasks returns lexer hits');
check($lexer->queries === ['status:open tag:core'], 'search_tasks forwards query to taskLexer');
check(
    isset($lexer->filters[0]) && $lexer->filters[0] instanceof TaskProjectFilter && $lexer->filters[0]->value === 2,
    'search_tasks scopes with TaskProjectFilter(project_id)'
);
check(($res['data'][0]['tags'] ?? null) === $taskTag->byTask[50], 'search_tasks attaches tags to tagged task');
check(($res['data'][1]['tags'] ?? null) === [], 'search_tasks attaches tags: [] to untagged task');
check(!isset($res['data'][0]['tags'][0]['task_id']), 'search_tasks tag rows omit task_id');
check(!array_key_exists('links', $res['data'][0] ?? []), 'search_tasks omits links by default');

$res = callTool($server, 'search_tasks', ['project_id' => 2, 'query' => 'status:open', 'include_links' => true]);
$expectedLink = [
    [
        'id' => 7,
        'opposite_task_id' => 51,
        'label' => 'is a parent of',
        'title' => 'Bare',
        'project_id' => 2,
    ],
];
check(($res['data'][0]['links'] ?? null) === $expectedLink, 'include_links attaches normalized links');
check(($res['data'][1]['links'] ?? null) === [], 'include_links on unlinked task is links: []');
check(!isset($res['data'][0]['links'][0]['task_id']), 'include_links remaps task_id alias to opposite_task_id');

$lexer->queries = [];
$lexer->filters = [];
$lexer->results = [];
$res = callTool($server, 'search_tasks', ['project_id' => 2, 'query' => '']);
check($res['isError'] === false && $res['data'] === [], 'search_tasks allows empty query and returns []');
check($lexer->queries === [''], 'search_tasks forwards empty query');

$res = callTool($server, 'search_tasks', ['project_id' => 2]);
check($res['isError'] === true, 'search_tasks rejects missing query');
$res = callTool($server, 'search_tasks', ['project_id' => 2, 'query' => 1]);
check($res['isError'] === true, 'search_tasks rejects non-string query');
$res = callTool($server, 'search_tasks', ['project_id' => 0, 'query' => 'status:open']);
check($res['isError'] === true, 'search_tasks rejects non-positive project_id');
$res = callTool($server, 'search_tasks', ['query' => 'status:open']);
check($res['isError'] === true, 'search_tasks rejects missing project_id');

echo "\n$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
