<?php

declare(strict_types=1);

namespace AmpacheMcp;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/AmpacheApiClient.php';
require_once __DIR__ . '/PersistentQueuePlaylist.php';
require_once __DIR__ . '/McpHttpServer.php';

function ampache_mcp_create_server(): McpHttpServer
{
    $config = ampache_mcp_config();

    return new McpHttpServer(
        new AmpacheApiClient(
            ampache_mcp_config_value($config, 'ampache_base_url'),
            ampache_mcp_config_value($config, 'ampache_api_key'),
            ampache_mcp_config_value($config, 'ampache_api_version', '8.0.0')
        ),
        new PersistentQueuePlaylist(
            ampache_mcp_config_value($config, 'ampache_root'),
            ampache_mcp_config_value($config, 'playlist_name', 'AI Queue'),
            ampache_mcp_config_value($config, 'playlist_type', 'public')
        ),
        ampache_mcp_config_value($config, 'user_token'),
        ampache_mcp_config_value($config, 'server_name', 'ampache-mcp')
    );
}

function ampache_mcp_handle(): void
{
    ampache_mcp_create_server()->handle();
}
