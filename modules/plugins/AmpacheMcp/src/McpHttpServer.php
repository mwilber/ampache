<?php

declare(strict_types=1);

namespace AmpacheMcp;

final class McpHttpServer
{
    private const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(
        private AmpacheApiClient $ampache,
        private NativeTemporaryPlaylist $temporaryPlaylist,
        private string $userToken,
        private string $serverName
    ) {
    }

    public function handle(): void
    {
        $this->sendCorsHeaders();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            return;
        }

        $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($path === '/' || str_ends_with($path, '/'))) {
            $this->landing();
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($path, '/health')) {
            ampache_mcp_json_response(['status' => 'ok', 'server' => $this->serverName]);
            return;
        }

        $authError = $this->authorizationError();
        if ($authError !== null) {
            ampache_mcp_json_response(
                ['message' => $authError['message']],
                $authError['status']
            );
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ampache_mcp_json_response($this->jsonRpcError(null, -32000, 'Method not allowed'), 405);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $request = json_decode($raw, true);
        if (!is_array($request)) {
            ampache_mcp_json_response($this->jsonRpcError(null, -32700, 'Parse error'), 400);
            return;
        }

        try {
            $response = $this->handleJsonRpc($request);
            if ($response === null) {
                http_response_code(202);
                return;
            }
            ampache_mcp_json_response($response, 200, ['mcp-session-id' => $this->sessionId()]);
        } catch (\Throwable $error) {
            ampache_mcp_json_response($this->jsonRpcError($request['id'] ?? null, -32603, $error->getMessage()), 500);
        }
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>|null
     */
    private function handleJsonRpc(array $request): ?array
    {
        $id = $request['id'] ?? null;
        $method = (string)($request['method'] ?? '');
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->jsonRpcResult($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => (object)[]],
                'serverInfo' => [
                    'name' => $this->serverName,
                    'version' => '0.1.0',
                ],
            ]),
            'tools/list' => $this->jsonRpcResult($id, ['tools' => $this->tools()]),
            'tools/call' => $this->jsonRpcResult($id, $this->callTool($params)),
            default => $this->jsonRpcError($id, -32601, 'Method not found'),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tools(): array
    {
        return [
            [
                'name' => 'ampache-search',
                'title' => 'Ampache: Search Music',
                'description' => 'Search Ampache songs by title, artist, album, or general music query.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'ampache-temporary-playlist',
                'title' => 'Ampache: Build Temporary Playlist',
                'description' => 'Replace or append to the Ampache temporary playlist with selected song ids. Can search first when query is provided.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'songIds' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer', 'minimum' => 1],
                            'description' => 'Song ids returned by ampache-search.',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Optional search query to resolve songs before building the playlist.',
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'clear' => ['type' => 'boolean', 'default' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = (string)($params['name'] ?? '');
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if ($name === 'ampache-search') {
            $query = trim((string)($args['query'] ?? ''));
            if ($query === '') {
                throw new \InvalidArgumentException('query is required.');
            }
            $songs = $this->ampache->searchSongs($query, (int)($args['limit'] ?? 10), (int)($args['offset'] ?? 0));
            $summary = $songs === []
                ? 'No songs matched the search.'
                : implode("\n", array_map([$this, 'formatSong'], array_slice($songs, 0, 20)));

            return [
                'content' => [['type' => 'text', 'text' => $summary]],
                'structuredContent' => ['total' => count($songs), 'songs' => $songs],
            ];
        }

        if ($name === 'ampache-temporary-playlist') {
            $songIds = $this->resolveSongIds($args);
            if ($songIds === []) {
                throw new \InvalidArgumentException('Provide songIds or a query that matches at least one song.');
            }

            $authSession = $this->ampache->getAuthToken();
            if ($this->temporaryPlaylist->isAvailable()) {
                $result = $this->temporaryPlaylist->replaceSongs($authSession, $songIds, (bool)($args['clear'] ?? true));
                $text = sprintf('Temporary playlist %d now contains %d song(s).', $result['id'], $result['total']);

                return [
                    'content' => [['type' => 'text', 'text' => $text]],
                    'structuredContent' => ['mode' => 'native_tmp_playlist'] + $result,
                ];
            }

            $result = $this->ampache->createPlaylistFromSongs($this->temporaryPlaylistName(), $songIds);

            return [
                'content' => [['type' => 'text', 'text' => sprintf('Created private playlist "%s" with %d song(s).', $result['playlist']['name'] ?? 'Temporary Playlist', count($result['added']))]],
                'structuredContent' => ['mode' => 'private_playlist_fallback'] + $result,
            ];
        }

        throw new \InvalidArgumentException('Unknown tool: ' . $name);
    }

    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    private function resolveSongIds(array $args): array
    {
        $ids = [];
        if (is_array($args['songIds'] ?? null)) {
            foreach ($args['songIds'] as $songId) {
                $songId = (int)$songId;
                if ($songId > 0) {
                    $ids[] = $songId;
                }
            }
        }

        $query = trim((string)($args['query'] ?? ''));
        if ($query !== '') {
            foreach ($this->ampache->searchSongs($query, (int)($args['limit'] ?? 25)) as $song) {
                $songId = (int)($song['id'] ?? 0);
                if ($songId > 0) {
                    $ids[] = $songId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $song
     */
    private function formatSong(array $song): string
    {
        $artist = $this->nameFromNode($song['artist'] ?? null);
        $album = $this->nameFromNode($song['album'] ?? null);
        $title = (string)($song['title'] ?? $song['name'] ?? 'Untitled');
        $id = (string)($song['id'] ?? '?');
        $parts = ['#' . $id, $title];
        if ($artist !== '') {
            $parts[] = $artist;
        }
        if ($album !== '') {
            $parts[] = $album;
        }

        return implode(' | ', $parts);
    }

    private function nameFromNode(mixed $node): string
    {
        if (is_array($node)) {
            return (string)($node['name'] ?? $node['title'] ?? '');
        }

        return (string)$node;
    }

    private function temporaryPlaylistName(): string
    {
        return 'AI Temporary Playlist ' . gmdate('Y-m-d H:i:s') . ' UTC';
    }

    /**
     * @return array{status: int, message: string}|null
     */
    private function authorizationError(): ?array
    {
        if ($this->userToken === '') {
            return [
                'status' => 503,
                'message' => 'Ampache MCP config is missing user_token.',
            ];
        }

        $headers = ampache_mcp_request_headers();
        $token = $headers['x-user-token'] ?? $headers['X-User-Token'] ?? '';
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        if ($token === '' && is_string($auth) && str_starts_with($auth, 'Bearer ')) {
            $token = substr($auth, 7);
        }

        if (!hash_equals($this->userToken, (string)$token)) {
            return [
                'status' => 401,
                'message' => 'user is not authenticated',
            ];
        }

        return null;
    }

    private function sessionId(): string
    {
        $headers = ampache_mcp_request_headers();
        $session = $headers['mcp-session-id'] ?? $headers['Mcp-Session-Id'] ?? '';
        if (is_string($session) && $session !== '') {
            return $session;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * @param mixed $id
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function jsonRpcResult(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @param mixed $id
     * @return array<string, mixed>
     */
    private function jsonRpcError(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    private function sendCorsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: content-type, authorization, x-user-token, mcp-session-id');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Expose-Headers: mcp-session-id');
    }

    private function landing(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Ampache MCP Server</title></head><body><main><h1>Ampache MCP Server</h1><p>POST JSON-RPC MCP requests to this endpoint. Health check: <code>/health</code>.</p></main></body></html>';
    }
}
