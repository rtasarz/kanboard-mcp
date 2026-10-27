<?php
declare(strict_types=1);

// get_projects / kanboard://projects must not expose the public-board token or
// the inbound e-mail address of a project.
// Run: php tests/McpServerProjectsTest.php

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

    public function getAll(): array
    {
        return $this->projects;
    }
}

$projects = new FakeProjectModel();
$projects->projects = [
    7 => [
        'id' => '7', 'name' => 'alpha', 'description' => 'd', 'identifier' => 'ALPHA',
        'is_active' => '1', 'is_public' => '1', 'is_private' => '0', 'is_everybody_allowed' => '0',
        'owner_id' => '1', 'start_date' => '', 'end_date' => '', 'last_modified' => '1700000000',
        'priority_default' => '0', 'priority_start' => '0', 'priority_end' => '3', 'task_limit' => '0',
        'per_swimlane_task_limits' => '0', 'enable_global_tags' => '1', 'default_swimlane' => 'Default', 'show_default_swimlane' => '1',
        'token' => 'deadbeefdeadbeefdeadbeefdeadbeef', 'email' => 'board@example.com', 'predefined_email_subjects' => 'x',
    ],
];
$server = new McpServer(new ArrayObject(['projectModel' => $projects]));

$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_projects', 'arguments' => []]]);
$text = $response['result']['content'][0]['text'] ?? '';
$rows = json_decode($text, true);
check(is_array($rows) && count($rows) === 1, 'get_projects returns the project');
foreach (['token', 'email', 'predefined_email_subjects'] as $field) {
    check(!array_key_exists($field, $rows[0]), "get_projects row has no $field");
}
check(strpos($text, 'deadbeef') === false, 'get_projects text has no share token');
check(strpos($text, 'board@example.com') === false, 'get_projects text has no e-mail');
foreach (['id', 'name', 'description', 'identifier', 'is_active', 'is_public', 'is_private', 'owner_id', 'last_modified', 'task_limit'] as $field) {
    check(array_key_exists($field, $rows[0]), "get_projects keeps $field");
}
check(($rows[0]['id'] ?? null) === '7', 'values are passed through unchanged');
check(!array_key_exists('token', $response['result']['structuredContent']['items'][0] ?? ['token' => 1]), 'structuredContent has no token');

$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => ['uri' => 'kanboard://projects']]);
$text = $response['result']['contents'][0]['text'] ?? '';
$rows = json_decode($text, true);
check(is_array($rows) && count($rows) === 1, 'resource returns the project');
check(!array_key_exists('token', $rows[0]) && !array_key_exists('email', $rows[0]), 'resource row has no token/email');
check(strpos($text, 'deadbeef') === false && strpos($text, 'board@example.com') === false, 'resource text has no secrets');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
