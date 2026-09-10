<?php
declare(strict_types=1);

namespace Kanboard\Plugin\ModelContextProtocol\Core;

final class McpAuth
{
    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $get
     */
    public static function tokenFromRequest(array $server, array $get): string
    {
        $header = '';
        if (isset($server['HTTP_AUTHORIZATION']) && is_string($server['HTTP_AUTHORIZATION'])) {
            $header = $server['HTTP_AUTHORIZATION'];
        } elseif (isset($server['REDIRECT_HTTP_AUTHORIZATION']) && is_string($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if ($header !== '' && preg_match('/^Bearer\s+(\S+)/i', $header, $m) === 1) {
            return $m[1];
        }

        $query = $get['token'] ?? '';

        return is_string($query) ? $query : '';
    }
}
