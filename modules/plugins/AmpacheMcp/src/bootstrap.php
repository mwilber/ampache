<?php

declare(strict_types=1);

namespace AmpacheMcp;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/AmpacheApiClient.php';
require_once __DIR__ . '/NativeTemporaryPlaylist.php';
require_once __DIR__ . '/McpHttpServer.php';

function ampache_mcp_create_server(): McpHttpServer
{
    return new McpHttpServer(
        new AmpacheApiClient(
            ampache_mcp_env('AMPACHE_BASE_URL', ''),
            ampache_mcp_env('AMPACHE_API_KEY', ''),
            ampache_mcp_env('AMPACHE_API_VERSION', '8.0.0')
        ),
        new NativeTemporaryPlaylist(ampache_mcp_env('AMPACHE_ROOT', '')),
        ampache_mcp_env('USER_TOKEN', ''),
        ampache_mcp_env('AMPACHE_MCP_SERVER_NAME', 'ampache-mcp')
    );
}

function ampache_mcp_handle(): void
{
    ampache_mcp_create_server()->handle();
}
