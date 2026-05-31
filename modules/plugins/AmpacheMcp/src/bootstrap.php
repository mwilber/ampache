<?php

declare(strict_types=1);

namespace AmpacheMcp;

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/AmpacheApiClient.php';
require_once __DIR__ . '/PushSubscriptionStore.php';
require_once __DIR__ . '/JsonPushSubscriptionStore.php';
require_once __DIR__ . '/PersistentQueuePlaylist.php';
require_once __DIR__ . '/SemanticMusicSearch.php';
require_once __DIR__ . '/McpHttpServer.php';
require_once __DIR__ . '/WebPushNotifier.php';

function ampache_mcp_create_server(): McpHttpServer
{
    $config = ampache_mcp_config();

    $pushStore = new JsonPushSubscriptionStore(
        ampache_mcp_config_value(
            $config,
            'push_subscription_file',
            dirname(__DIR__) . '/data/push-subscriptions.json'
        )
    );

    return new McpHttpServer(
        new AmpacheApiClient(
            ampache_mcp_config_value($config, 'ampache_base_url'),
            ampache_mcp_config_value($config, 'ampache_api_key'),
            ampache_mcp_config_value($config, 'ampache_api_version', '6.0.0')
        ),
        new PersistentQueuePlaylist(
            ampache_mcp_config_value($config, 'ampache_root'),
            ampache_mcp_config_value($config, 'playlist_name', 'AI Queue'),
            ampache_mcp_config_value($config, 'playlist_type', 'public')
        ),
        new SemanticMusicSearch(ampache_mcp_config_value($config, 'ampache_root')),
        $pushStore,
        new WebPushNotifier(
            $pushStore,
            ampache_mcp_config_value($config, 'vapid_subject'),
            ampache_mcp_config_value($config, 'vapid_public_key'),
            ampache_mcp_config_value($config, 'vapid_private_key'),
            ampache_mcp_config_value($config, 'push_click_url')
        ),
        ampache_mcp_config_value($config, 'user_token'),
        ampache_mcp_config_value($config, 'server_name', 'ampache-mcp')
    );
}

function ampache_mcp_handle(): void
{
    ampache_mcp_create_server()->handle();
}
