# Ampache MCP Plugin

Self-contained PHP MCP endpoint for Ampache. It exposes two tools:

- `ampache-search`: search Ampache songs.
- `ampache-temporary-playlist`: build the persistent `AI Queue` playlist from song ids or a search query.

## Deploy

Copy the plugin paths into the Ampache project root, preserving the directory layout:

```text
modules/plugins/AmpacheMcp/
public/AmpacheMcp/
```

Ampache's `public/` directory is the web server root, so only the small files in `public/AmpacheMcp/` are web-facing. The implementation stays in `modules/plugins/AmpacheMcp/src/`.

## Configure

Copy the sample config and edit it on the server:

```bash
cp modules/plugins/AmpacheMcp/config.php.dist modules/plugins/AmpacheMcp/config.php
```

Required config values:

- `user_token`: shared token the remote AI agent must send as `x-user-token` or `Authorization: Bearer`.
- `ampache_base_url`: public Ampache base URL, for example `https://music.example.com`.
- `ampache_api_key`: Ampache API key for the user whose library and `AI Queue` playlist should be controlled.
- `ampache_root`: local filesystem path to the Ampache install. Required so the MCP server can create or replace the persistent queue playlist.

Recommended config values:

- `ampache_api_version`: defaults to `6.0.0`, which works across current Ampache 7 installs and older API compatibility modes.
- `playlist_name`: defaults to `AI Queue`.
- `playlist_type`: defaults to `public`, so a separate Subsonic player user can see the queue. Set to `private` if the player authenticates as the same Ampache user as the MCP API key.
- `push_subscription_file`: writable JSON file used to store PWA push subscriptions.
- `vapid_subject`, `vapid_public_key`, `vapid_private_key`: Web Push VAPID settings. If keys are blank, subscriptions can be stored but notifications are not sent.
- `push_click_url`: PWA URL opened when the notification is clicked.

## Endpoints

- `GET /AmpacheMcp/health` returns service health when `.htaccess` rewrite rules are enabled.
- `GET /AmpacheMcp/index.php/health` returns service health without rewrite rules.
- `GET /AmpacheMcp/push/public-key` returns the VAPID public key for the PWA.
- `GET /AmpacheMcp/push/check` shows a simple browser diagnostics page for subscription JSON-file writability.
- `POST /AmpacheMcp/push/subscribe` stores a PWA `PushSubscription`. Requires the shared user token.
- `POST /AmpacheMcp/push/unsubscribe` removes a stored PWA subscription. Requires the shared user token.
- `POST /AmpacheMcp/push/test` sends a test notification to stored subscriptions. Requires the shared user token.
- `POST /AmpacheMcp/mcp.php` accepts MCP JSON-RPC requests.
- `POST /AmpacheMcp/` also accepts MCP JSON-RPC requests when the web server routes the request to `index.php`.

The endpoint follows the same web-accessible pattern as `rtm-mcp`: public health/landing routes, token auth for MCP, CORS with `mcp-session-id` exposed, and streamable HTTP-compatible JSON-RPC responses.

See `PWA_PUSH_NOTIFICATIONS.md` for the player-side service worker and subscription flow.
See `COMPANION_AGENT_INSTRUCTIONS.md` for agent-side handling of semantic search results and album queueing.
