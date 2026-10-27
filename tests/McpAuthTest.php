<?php
declare(strict_types=1);

// Run: php tests/McpAuthTest.php

use Kanboard\Plugin\ModelContextProtocol\Core\McpAuth;

require __DIR__ . '/../Core/McpAuth.php';

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

check(McpAuth::tokenFromRequest(['HTTP_AUTHORIZATION' => 'Bearer abc']) === 'abc', 'Bearer header');
check(McpAuth::tokenFromRequest(['HTTP_AUTHORIZATION' => 'bearer xyz']) === 'xyz', 'Bearer is case-insensitive');
check(McpAuth::tokenFromRequest(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer def']) === 'def', 'REDIRECT_HTTP_AUTHORIZATION');
check(McpAuth::tokenFromRequest(['QUERY_STRING' => 'token=qtok']) === '', 'query-string token is ignored');
check(McpAuth::tokenFromRequest([]) === '', 'empty request');
check(McpAuth::tokenFromRequest(['HTTP_AUTHORIZATION' => 'Basic abc']) === '', 'non-Bearer header yields no token');
check(McpAuth::tokenFromRequest(['HTTP_AUTHORIZATION' => 'Bearer']) === '', 'Bearer without a token yields no token');

echo "\n$checks checks, $failures failed\n";
exit($failures === 0 ? 0 : 1);
