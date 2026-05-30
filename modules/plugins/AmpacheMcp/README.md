# Ampache MCP Plugin

Self-contained PHP MCP endpoint for Ampache. It exposes two tools:

- `ampache-search`: search Ampache songs.
- `ampache-temporary-playlist`: build the authenticated user's Ampache temporary playlist from song ids or a search query.

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
- `ampache_api_key`: Ampache API key for the user whose library and temporary playlist should be controlled.

Recommended config values:

- `ampache_root`: local filesystem path to the Ampache install. When set, the MCP server writes to Ampache's native `tmp_playlist` tables for the API session. Without it, the server falls back to creating a private playlist named `AI Temporary Playlist ...`.
- `ampache_api_version`: defaults to `8.0.0`.

## Endpoints

- `GET /AmpacheMcp/health` returns service health when `.htaccess` rewrite rules are enabled.
- `GET /AmpacheMcp/index.php/health` returns service health without rewrite rules.
- `POST /AmpacheMcp/mcp.php` accepts MCP JSON-RPC requests.
- `POST /AmpacheMcp/` also accepts MCP JSON-RPC requests when the web server routes the request to `index.php`.

The endpoint follows the same web-accessible pattern as `rtm-mcp`: public health/landing routes, token auth for MCP, CORS with `mcp-session-id` exposed, and streamable HTTP-compatible JSON-RPC responses.
