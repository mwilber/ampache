# Ampache MCP Plugin

Self-contained PHP MCP endpoint for Ampache. It exposes two tools:

- `ampache-search`: search Ampache songs.
- `ampache-temporary-playlist`: build the authenticated user's Ampache temporary playlist from song ids or a search query.

## Deploy

Expose `public/index.php` with PHP-FPM or Apache. Do not route this through Ampache core files.

Required environment:

- `USER_TOKEN`: shared token the remote AI agent must send as `x-user-token` or `Authorization: Bearer`.
- `AMPACHE_BASE_URL`: public Ampache base URL, for example `https://music.example.com`.
- `AMPACHE_API_KEY`: Ampache API key for the user whose library and temporary playlist should be controlled.

Recommended:

- `AMPACHE_ROOT`: local filesystem path to the Ampache install. When set, the MCP server writes to Ampache's native `tmp_playlist` tables for the API session. Without it, the server falls back to creating a private playlist named `AI Temporary Playlist ...`.
- `AMPACHE_API_VERSION`: defaults to `8.0.0`.

## Endpoints

- `GET /health` returns service health.
- `POST /` or `POST /mcp.php` accepts MCP JSON-RPC requests. Configure your web server rewrite/alias to expose this as `/mcp` if your client expects the RTM-style path.

The endpoint follows the same web-accessible pattern as `rtm-mcp`: public health/landing routes, token auth for MCP, CORS with `mcp-session-id` exposed, and streamable HTTP-compatible JSON-RPC responses.
