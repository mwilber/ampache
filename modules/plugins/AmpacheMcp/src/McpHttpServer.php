<?php

declare(strict_types=1);

namespace AmpacheMcp;

final class McpHttpServer
{
    private const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(
        private AmpacheApiClient $ampache,
        private PersistentQueuePlaylist $queuePlaylist,
        private SemanticMusicSearch $musicSearch,
        private PushSubscriptionStore $pushSubscriptions,
        private WebPushNotifier $pushNotifier,
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
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($path, '/push/public-key')) {
            ampache_mcp_json_response([
                'publicKey' => $this->pushNotifier->publicKey(),
                'configured' => $this->pushNotifier->isConfigured(),
            ]);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && str_ends_with($path, '/push/check')) {
            $this->pushCheckPage();
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
        if (str_contains($path, '/push/')) {
            try {
                $this->handlePushRoute($path);
            } catch (\Throwable $error) {
                ampache_mcp_json_response(['message' => $error->getMessage()], 400);
            }
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
                'description' => 'Search Ampache music and return semantic song, album, and artist candidates. The text content starts with the best interpretation.',
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
                'title' => 'Ampache: Build AI Queue',
                'description' => 'Replace or append to the persistent Ampache playlist named AI Queue with selected song ids. Can search first when query is provided.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'songIds' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer', 'minimum' => 1],
                            'description' => 'Song ids returned by ampache-search.',
                        ],
                        'albumId' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'description' => 'Album id returned by ampache-search. Queues the full album in track order.',
                        ],
                        'albumIds' => [
                            'type' => 'array',
                            'items' => ['type' => 'integer', 'minimum' => 1],
                            'description' => 'Album ids returned by ampache-search. Queues all selected albums in track order.',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Optional search query to resolve songs or a high-confidence album before building the playlist.',
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
            $search = $this->musicSearch->describe($query, $songs, (int)($args['limit'] ?? 10));

            return [
                'content' => [['type' => 'text', 'text' => $search['content']]],
                'structuredContent' => $search['structured'],
            ];
        }

        if ($name === 'ampache-temporary-playlist') {
            $songIds = $this->resolveSongIds($args);
            if ($songIds === []) {
                throw new \InvalidArgumentException('Provide songIds or a query that matches at least one song.');
            }

            $authSession = $this->ampache->getAuthToken();
            $result = $this->queuePlaylist->replaceSongs($authSession, $songIds, (bool)($args['clear'] ?? true));
            $text = sprintf('Playlist "%s" now contains %d song(s).', $result['name'], $result['total']);
            $push = $this->pushNotifier->send([
                'title' => 'AI Queue ready',
                'body' => sprintf('%d song(s) ready in %s.', $result['total'], $result['name']),
                'data' => [
                    'playlistId' => $result['id'],
                    'playlistName' => $result['name'],
                    'total' => $result['total'],
                    'added' => $result['added'],
                ],
            ]);

            return [
                'content' => [['type' => 'text', 'text' => $text]],
                'structuredContent' => ['mode' => 'persistent_playlist', 'push' => $push] + $result,
            ];
        }

        throw new \InvalidArgumentException('Unknown tool: ' . $name);
    }

    private function handlePushRoute(string $path): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            ampache_mcp_json_response(['message' => 'Method not allowed'], 405);
            return;
        }

        $body = $this->jsonBody();
        if (str_ends_with($path, '/push/subscribe')) {
            $headers = ampache_mcp_request_headers();
            $record = $this->pushSubscriptions->save($body, (string)($headers['User-Agent'] ?? $headers['user-agent'] ?? ''));
            ampache_mcp_json_response([
                'status' => 'subscribed',
                'id' => $record['id'],
                'subscriptions' => count($this->pushSubscriptions->all()),
            ]);
            return;
        }

        if (str_ends_with($path, '/push/unsubscribe')) {
            $endpoint = (string)($body['endpoint'] ?? '');
            $id = (string)($body['id'] ?? '');
            $deleted = $endpoint !== ''
                ? $this->pushSubscriptions->deleteByEndpoint($endpoint)
                : ($id !== '' && $this->pushSubscriptions->deleteById($id));
            ampache_mcp_json_response([
                'status' => $deleted ? 'unsubscribed' : 'not_found',
                'subscriptions' => count($this->pushSubscriptions->all()),
            ]);
            return;
        }

        if (str_ends_with($path, '/push/test')) {
            $result = $this->pushNotifier->send([
                'title' => (string)($body['title'] ?? 'Ampache MCP test'),
                'body' => (string)($body['body'] ?? 'Push notifications are connected.'),
                'url' => (string)($body['url'] ?? ''),
                'tag' => 'ampache-mcp-test',
            ]);
            ampache_mcp_json_response(['status' => 'sent', 'push' => $result]);
            return;
        }

        ampache_mcp_json_response(['message' => 'Not found'], 404);
    }

    private function pushCheckPage(): void
    {
        $diagnostics = method_exists($this->pushSubscriptions, 'diagnostics')
            ? $this->pushSubscriptions->diagnostics()
            : [
                'path' => 'unknown',
                'directory' => 'unknown',
                'directoryExists' => false,
                'directoryWritable' => false,
                'fileExists' => false,
                'fileWritable' => false,
                'canWrite' => false,
                'message' => 'The configured subscription store does not expose diagnostics.',
            ];

        http_response_code($diagnostics['canWrite'] ? 200 : 500);
        header('Content-Type: text/html; charset=utf-8');

        $status = $diagnostics['canWrite'] ? 'Writable' : 'Not writable';
        $statusClass = $diagnostics['canWrite'] ? 'ok' : 'bad';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Ampache MCP Push Storage Check</title>';
        echo '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:2rem;line-height:1.5;max-width:760px}';
        echo '.status{display:inline-block;padding:.35rem .6rem;border-radius:.35rem;font-weight:700}.ok{background:#e5f7ed;color:#17633a}.bad{background:#fde8e8;color:#9b1c1c}';
        echo 'dl{display:grid;grid-template-columns:max-content 1fr;gap:.5rem 1rem}dt{font-weight:700}dd{margin:0;word-break:break-all}code{background:#f4f4f5;padding:.1rem .25rem;border-radius:.25rem}</style>';
        echo '</head><body>';
        echo '<h1>Ampache MCP Push Storage Check</h1>';
        echo '<p><span class="status ' . $statusClass . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span></p>';
        echo '<p>' . htmlspecialchars((string)$diagnostics['message'], ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<dl>';
        foreach ([
            'Subscription file' => 'path',
            'Directory' => 'directory',
            'Directory exists' => 'directoryExists',
            'Directory writable' => 'directoryWritable',
            'File exists' => 'fileExists',
            'File writable' => 'fileWritable',
        ] as $label => $key) {
            $value = $diagnostics[$key] ?? '';
            $text = is_bool($value) ? ($value ? 'yes' : 'no') : (string)$value;
            echo '<dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd><code>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</code></dd>';
        }
        echo '</dl>';
        echo '</body></html>';
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \InvalidArgumentException('Request body must be JSON.');
        }

        return $body;
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

        $albumIds = [];
        $albumId = (int)($args['albumId'] ?? 0);
        if ($albumId > 0) {
            $albumIds[] = $albumId;
        }
        if (is_array($args['albumIds'] ?? null)) {
            foreach ($args['albumIds'] as $candidateAlbumId) {
                $candidateAlbumId = (int)$candidateAlbumId;
                if ($candidateAlbumId > 0) {
                    $albumIds[] = $candidateAlbumId;
                }
            }
        }
        if ($albumIds !== []) {
            $ids = array_merge($ids, $this->musicSearch->songIdsForAlbumIds($albumIds));
        }

        $query = trim((string)($args['query'] ?? ''));
        if ($query !== '') {
            $songs = $this->ampache->searchSongs($query, (int)($args['limit'] ?? 25));
            $search = $this->musicSearch->describe($query, $songs, (int)($args['limit'] ?? 25));
            $bestMatch = is_array($search['structured']['bestMatch'] ?? null) ? $search['structured']['bestMatch'] : null;
            if (($bestMatch['type'] ?? '') === 'album' && ($bestMatch['confidence'] ?? '') === 'high') {
                $ids = array_merge($ids, array_map('intval', $bestMatch['songIds'] ?? []));
            } else {
                foreach ($songs as $song) {
                    $songId = (int)($song['id'] ?? 0);
                    if ($songId > 0) {
                        $ids[] = $songId;
                    }
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
