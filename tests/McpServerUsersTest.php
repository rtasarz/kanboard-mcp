<?php
declare(strict_types=1);

// get_users / kanboard://users must not expose credentials from the users table.
// Run: php tests/McpServerUsersTest.php

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

final class FakeUserModel
{
    public array $users = [];

    public function getAll(): array
    {
        return $this->users;
    }
}

// Shape of UserModel::getAll(): every column of the users table.
const SECRET_FIELDS = [
    'password',
    'twofactor_secret',
    'api_access_token',
    'token',
    'google_id',
    'github_id',
    'gitlab_id',
    'nb_failed_login',
    'lock_expiration_date',
];

$users = new FakeUserModel();
$users->users = [
    3 => [
        'id' => '3',
        'username' => 'alice',
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'role' => 'app-admin',
        'is_active' => '1',
        'timezone' => 'Europe/Zurich',
        'language' => 'en_US',
        'password' => '$2y$10$abcdefghijklmnopqrstuv',
        'twofactor_secret' => 'JBSWY3DPEHPK3PXP',
        'twofactor_activated' => '1',
        'api_access_token' => 'apitoken-123',
        'token' => 'remember-me-456',
        'google_id' => 'g-1',
        'github_id' => 'gh-1',
        'gitlab_id' => 'gl-1',
        'nb_failed_login' => '2',
        'lock_expiration_date' => '0',
        'notifications_enabled' => '1',
        'notifications_filter' => '4',
        'avatar_path' => null,
        'theme' => 'light',
        'filter' => 'status:open',
    ],
    4 => [
        'id' => '4',
        'username' => 'bob',
        'name' => null,
        'email' => null,
        'role' => 'app-user',
        'is_active' => '0',
        'password' => '$2y$10$zzzzzzzzzzzzzzzzzzzzzz',
        'token' => '',
        'api_access_token' => null,
        'twofactor_secret' => null,
    ],
];

$server = new McpServer(new ArrayObject(['userModel' => $users]));

// --- get_users tool ---
$response = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 1,
    'method' => 'tools/call',
    'params' => ['name' => 'get_users', 'arguments' => []],
]);

check(!isset($response['error']) && empty($response['result']['isError']), 'get_users succeeds');
$text = $response['result']['content'][0]['text'] ?? '';
$rows = json_decode($text, true);
check(is_array($rows) && count($rows) === 2, 'get_users returns both users');

foreach (SECRET_FIELDS as $field) {
    check(strpos($text, '"' . $field . '"') === false, "get_users text blob has no $field");
    foreach ($rows as $i => $row) {
        check(!array_key_exists($field, $row), "get_users row $i has no $field");
    }
}
check(strpos($text, '$2y$10$') === false, 'get_users text blob contains no bcrypt hash');
check(strpos($text, 'JBSWY3DPEHPK3PXP') === false, 'get_users text blob contains no 2FA secret');
check(strpos($text, 'apitoken-123') === false, 'get_users text blob contains no API token');
check(strpos($text, 'remember-me-456') === false, 'get_users text blob contains no remember-me token');

check(($rows[0]['id'] ?? null) === 3, 'id is cast to int');
check(($rows[0]['is_active'] ?? null) === 1, 'is_active is cast to int');
check(($rows[0]['username'] ?? null) === 'alice', 'username kept');
check(($rows[0]['name'] ?? null) === 'Alice', 'name kept');
check(($rows[0]['email'] ?? null) === 'alice@example.com', 'email kept');
check(($rows[0]['role'] ?? null) === 'app-admin', 'role kept');
check(($rows[0]['timezone'] ?? null) === 'Europe/Zurich', 'timezone kept');
check(($rows[0]['language'] ?? null) === 'en_US', 'language kept');
check(array_keys($rows[0]) === ['id', 'username', 'name', 'email', 'role', 'is_active', 'timezone', 'language'], 'exactly the allow-listed keys, in order');
check(($rows[1]['name'] ?? 'missing') === '' && ($rows[1]['email'] ?? 'missing') === '', 'null name/email become empty strings');
check(($rows[1]['is_active'] ?? null) === 0, 'inactive user is_active is 0');

$structured = $response['result']['structuredContent']['items'] ?? null;
check(is_array($structured) && count($structured) === 2, 'structuredContent.items present');
foreach (SECRET_FIELDS as $field) {
    check(!array_key_exists($field, $structured[0] ?? []), "structuredContent has no $field");
}

// --- kanboard://users resource ---
$response = $server->handleRequest([
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'resources/read',
    'params' => ['uri' => 'kanboard://users'],
]);

check(!isset($response['error']), 'resources/read kanboard://users succeeds');
$text = $response['result']['contents'][0]['text'] ?? '';
$rows = json_decode($text, true);
check(is_array($rows) && count($rows) === 2, 'resource returns both users');

foreach (SECRET_FIELDS as $field) {
    check(strpos($text, '"' . $field . '"') === false, "resource text has no $field");
}
check(strpos($text, '$2y$10$') === false, 'resource text contains no bcrypt hash');
check(strpos($text, 'JBSWY3DPEHPK3PXP') === false, 'resource text contains no 2FA secret');
check(strpos($text, 'apitoken-123') === false, 'resource text contains no API token');
check(strpos($text, 'remember-me-456') === false, 'resource text contains no remember-me token');
check(($rows[0]['username'] ?? null) === 'alice' && ($rows[0]['id'] ?? null) === 3, 'resource keeps id/username');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
