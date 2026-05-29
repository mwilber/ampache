<?php

declare(strict_types=1);

namespace AmpacheMcp;

final class AmpacheApiClient
{
    private string $baseUrl;

    private string $apiKey;

    private string $apiVersion;

    private ?string $authToken = null;

    public function __construct(string $baseUrl, string $apiKey, string $apiVersion)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiVersion = $apiVersion;
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchSongs(string $query, int $limit = 10, int $offset = 0): array
    {
        $response = $this->request('search_songs', [
            'filter' => $query,
            'limit' => max(1, min($limit, 100)),
            'offset' => max(0, $offset),
        ]);

        return $this->normalizeList($response['song'] ?? []);
    }

    /**
     * @param list<int> $songIds
     * @return array{playlist: array<string, mixed>, added: list<int>}
     */
    public function createPlaylistFromSongs(string $name, array $songIds, string $type = 'private'): array
    {
        $playlistResponse = $this->request('playlist_create', [
            'name' => $name,
            'type' => $type === 'public' ? 'public' : 'private',
        ]);
        $playlist = $this->normalizePlaylist($playlistResponse);
        $playlistId = (string)($playlist['id'] ?? '');
        if ($playlistId === '') {
            throw new \RuntimeException('Ampache did not return a playlist id.');
        }

        $added = [];
        foreach ($songIds as $songId) {
            $this->request('playlist_add', [
                'filter' => $playlistId,
                'id' => (string)$songId,
                'type' => 'song',
            ]);
            $added[] = $songId;
        }

        return [
            'playlist' => $playlist,
            'added' => $added,
        ];
    }

    public function getAuthToken(): string
    {
        if ($this->authToken === null) {
            $response = $this->request('handshake', [
                'auth' => $this->apiKey,
                'version' => $this->apiVersion,
            ], false);

            $token = (string)($response['auth'] ?? '');
            if ($token === '') {
                throw new \RuntimeException('Ampache handshake did not return an auth token.');
            }

            $this->authToken = $token;
        }

        return $this->authToken;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $action, array $params = [], bool $authenticated = true): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('AMPACHE_BASE_URL and AMPACHE_API_KEY must be configured.');
        }

        $url = $this->baseUrl . '/server/json.server.php';
        $payload = array_merge(['action' => $action], $params);
        if ($authenticated && !isset($payload['auth'])) {
            $payload['auth'] = $this->getAuthToken();
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new \RuntimeException('Ampache API request failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Ampache API returned HTTP %d: %s', $status, $body));
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Ampache API returned invalid JSON.');
        }
        if (isset($data['error'])) {
            $message = is_array($data['error']) ? json_encode($data['error']) : (string)$data['error'];
            throw new \RuntimeException('Ampache API error: ' . $message);
        }

        return $data;
    }

    /**
     * @param mixed $node
     * @return list<array<string, mixed>>
     */
    private function normalizeList(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }

        if ($node === [] || array_is_list($node)) {
            return array_values(array_filter($node, 'is_array'));
        }

        return [$node];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function normalizePlaylist(array $response): array
    {
        $playlist = $response['playlist'] ?? [];
        if (is_array($playlist) && array_is_list($playlist)) {
            return is_array($playlist[0] ?? null) ? $playlist[0] : [];
        }

        return is_array($playlist) ? $playlist : [];
    }
}
