<?php
declare(strict_types=1);

// A JSON-RPC id that is not a string, an integer or null must produce an
// Invalid Request error instead of a PHP TypeError.
// Run: php tests/McpServerRequestIdTest.php

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

$server = new McpServer(new ArrayObject([]));

foreach ([['a' => 1], [1, 2], 1.5, true] as $badId) {
    $label = json_encode($badId);
    try {
        $response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => $badId, 'method' => 'ping']);
        check(($response['error']['code'] ?? null) === -32600, "id $label -> -32600 Invalid Request");
        check(array_key_exists('id', $response) && $response['id'] === null, "id $label -> response id is null");
    } catch (Throwable $e) {
        check(false, "id $label must not throw (" . get_class($e) . ')');
    }
}

$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping']);
check(($response['id'] ?? null) === 7 && isset($response['result']), 'integer id still works');
$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => 'abc', 'method' => 'ping']);
check(($response['id'] ?? null) === 'abc' && isset($response['result']), 'string id still works');
$response = $server->handleRequest(['jsonrpc' => '2.0', 'id' => null, 'method' => 'ping']);
check(is_array($response) && array_key_exists('id', $response) && $response['id'] === null, 'null id still works');
$response = $server->handleRequest(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
check($response === null, 'notification without id still returns null');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
