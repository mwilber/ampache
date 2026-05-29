<?php

declare(strict_types=1);

use AmpacheMcp\AmpacheApiClient;
use AmpacheMcp\McpHttpServer;
use AmpacheMcp\NativeTemporaryPlaylist;

use function AmpacheMcp\ampache_mcp_env;

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/AmpacheApiClient.php';
require __DIR__ . '/../src/NativeTemporaryPlaylist.php';
require __DIR__ . '/../src/McpHttpServer.php';

$server = new McpHttpServer(
    new AmpacheApiClient(
        ampache_mcp_env('AMPACHE_BASE_URL', ''),
        ampache_mcp_env('AMPACHE_API_KEY', ''),
        ampache_mcp_env('AMPACHE_API_VERSION', '8.0.0')
    ),
    new NativeTemporaryPlaylist(ampache_mcp_env('AMPACHE_ROOT', '')),
    ampache_mcp_env('USER_TOKEN', ''),
    ampache_mcp_env('AMPACHE_MCP_SERVER_NAME', 'ampache-mcp')
);

$server->handle();
