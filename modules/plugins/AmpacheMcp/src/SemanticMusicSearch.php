<?php

declare(strict_types=1);

namespace AmpacheMcp;

use Ampache\Module\System\Dba;

final class SemanticMusicSearch
{
    private string $ampacheRoot;

    private bool $bootstrapped = false;

    public function __construct(string $ampacheRoot)
    {
        $this->ampacheRoot = rtrim($ampacheRoot, '/');
    }

    public function isAvailable(): bool
    {
        return $this->ampacheRoot !== '' && is_file($this->ampacheRoot . '/src/Config/Init.php');
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @return array{content: string, structured: array<string, mixed>}
     */
    public function describe(string $query, array $songs, int $limit): array
    {
        $albums = $this->albumCandidates($query, $songs, $limit);
        $artists = $this->artistCandidates($query, $songs, $limit);
        $songCandidates = $this->songCandidates($query, $songs);
        $bestMatch = $this->bestMatch($albums, $artists, $songCandidates);

        return [
            'content' => $this->content($query, $bestMatch, $albums, $artists, $songCandidates, $songs),
            'structured' => [
                'query' => $query,
                'bestMatch' => $bestMatch,
                'albums' => $albums,
                'artists' => $artists,
                'songs' => $songs,
                'totalSongs' => count($songs),
            ],
        ];
    }

    /**
     * @param list<int> $albumIds
     * @return list<int>
     */
    public function songIdsForAlbumIds(array $albumIds): array
    {
        $this->bootstrap();

        $ids = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $albumIds)))) as $albumId) {
            $ids = array_merge($ids, $this->albumSongIds($albumId));
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @return list<array<string, mixed>>
     */
    private function albumCandidates(string $query, array $songs, int $limit): array
    {
        $byId = [];
        foreach ($songs as $song) {
            $album = is_array($song['album'] ?? null) ? $song['album'] : [];
            $albumId = (int)($album['id'] ?? 0);
            if ($albumId < 1) {
                continue;
            }

            $artist = $this->nameFromNode($song['albumartist'] ?? $song['artist'] ?? null);
            $byId[$albumId] ??= [
                'type' => 'album',
                'albumId' => $albumId,
                'album' => $this->nameFromNode($album),
                'artist' => $artist,
                'songIds' => [],
                'shownSongIds' => [],
                'trackCount' => 0,
                'score' => 0,
                'confidence' => 'low',
                'reason' => '',
            ];
            $songId = (int)($song['id'] ?? 0);
            if ($songId > 0) {
                $byId[$albumId]['shownSongIds'][] = $songId;
                $byId[$albumId]['songIds'][] = $songId;
            }
        }

        foreach ($this->nativeAlbumCandidates($query, $limit) as $album) {
            $albumId = (int)$album['albumId'];
            $byId[$albumId] = array_merge($byId[$albumId] ?? [], $album);
        }

        $albums = array_values($byId);
        foreach ($albums as &$album) {
            if ((int)($album['albumId'] ?? 0) > 0 && $this->isAvailable()) {
                $album['songIds'] = $this->albumSongIds((int)$album['albumId']);
            }
            $album['songIds'] = array_values(array_unique(array_map('intval', $album['songIds'] ?? [])));
            $album['shownSongIds'] = array_values(array_unique(array_map('intval', $album['shownSongIds'] ?? [])));
            $album['trackCount'] = max((int)($album['trackCount'] ?? 0), count($album['songIds']));
            $album['score'] = $this->score($query, (string)($album['album'] ?? ''), 100, 60, 35)
                + min(count($album['shownSongIds']) * 3, 30);
            $album['confidence'] = $this->confidence((int)$album['score']);
            $album['reason'] = $this->reason($query, (string)($album['album'] ?? ''), 'album');
        }
        unset($album);

        usort($albums, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['album'], (string)$b['album']));

        return array_slice($albums, 0, 10);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nativeAlbumCandidates(string $query, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $this->bootstrap();
        $like = '%' . $query . '%';
        $sql = "SELECT `album`.`id`, `album`.`name`, `artist`.`name` AS `artist_name`, COUNT(`song`.`id`) AS `track_count`
            FROM `album`
            LEFT JOIN `artist` ON `artist`.`id` = `album`.`album_artist`
            LEFT JOIN `song` ON `song`.`album` = `album`.`id`
            WHERE LOWER(`album`.`name`) = LOWER(?) OR LOWER(`album`.`name`) LIKE LOWER(?)
            GROUP BY `album`.`id`, `album`.`name`, `artist`.`name`
            ORDER BY CASE WHEN LOWER(`album`.`name`) = LOWER(?) THEN 0 ELSE 1 END, `album`.`name`
            LIMIT ?";
        $dbResults = Dba::read($sql, [$query, $like, $query, max(10, $limit)]);
        $albums = [];
        while ($row = Dba::fetch_assoc($dbResults)) {
            $albumId = (int)($row['id'] ?? 0);
            if ($albumId < 1) {
                continue;
            }
            $albums[] = [
                'type' => 'album',
                'albumId' => $albumId,
                'album' => (string)($row['name'] ?? ''),
                'artist' => (string)($row['artist_name'] ?? ''),
                'songIds' => $this->albumSongIds($albumId),
                'shownSongIds' => [],
                'trackCount' => (int)($row['track_count'] ?? 0),
            ];
        }

        return $albums;
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @return list<array<string, mixed>>
     */
    private function artistCandidates(string $query, array $songs, int $limit): array
    {
        $byId = [];
        foreach ($songs as $song) {
            $artist = is_array($song['artist'] ?? null) ? $song['artist'] : [];
            $artistId = (int)($artist['id'] ?? 0);
            if ($artistId < 1) {
                continue;
            }
            $byId[$artistId] ??= [
                'type' => 'artist',
                'artistId' => $artistId,
                'artist' => $this->nameFromNode($artist),
                'songIds' => [],
                'score' => 0,
                'confidence' => 'low',
                'reason' => '',
            ];
            $songId = (int)($song['id'] ?? 0);
            if ($songId > 0) {
                $byId[$artistId]['songIds'][] = $songId;
            }
        }

        foreach ($this->nativeArtistCandidates($query, $limit) as $artist) {
            $artistId = (int)$artist['artistId'];
            $byId[$artistId] = array_merge($byId[$artistId] ?? [], $artist);
        }

        $artists = array_values($byId);
        foreach ($artists as &$artist) {
            $artist['songIds'] = array_values(array_unique(array_map('intval', $artist['songIds'] ?? [])));
            $artist['score'] = $this->score($query, (string)($artist['artist'] ?? ''), 80, 50, 25)
                + min(count($artist['songIds']), 20);
            $artist['confidence'] = $this->confidence((int)$artist['score']);
            $artist['reason'] = $this->reason($query, (string)($artist['artist'] ?? ''), 'artist');
        }
        unset($artist);

        usort($artists, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['artist'], (string)$b['artist']));

        return array_slice($artists, 0, 10);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nativeArtistCandidates(string $query, int $limit): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $this->bootstrap();
        $like = '%' . $query . '%';
        $sql = "SELECT `artist`.`id`, `artist`.`name`, COUNT(`song`.`id`) AS `song_count`
            FROM `artist`
            LEFT JOIN `song` ON `song`.`artist` = `artist`.`id`
            WHERE LOWER(`artist`.`name`) = LOWER(?) OR LOWER(`artist`.`name`) LIKE LOWER(?)
            GROUP BY `artist`.`id`, `artist`.`name`
            ORDER BY CASE WHEN LOWER(`artist`.`name`) = LOWER(?) THEN 0 ELSE 1 END, `artist`.`name`
            LIMIT ?";
        $dbResults = Dba::read($sql, [$query, $like, $query, max(10, $limit)]);
        $artists = [];
        while ($row = Dba::fetch_assoc($dbResults)) {
            $artists[] = [
                'type' => 'artist',
                'artistId' => (int)($row['id'] ?? 0),
                'artist' => (string)($row['name'] ?? ''),
                'songCount' => (int)($row['song_count'] ?? 0),
                'songIds' => [],
            ];
        }

        return $artists;
    }

    /**
     * @param list<array<string, mixed>> $songs
     * @return list<array<string, mixed>>
     */
    private function songCandidates(string $query, array $songs): array
    {
        $candidates = [];
        foreach ($songs as $song) {
            $title = (string)($song['title'] ?? $song['name'] ?? '');
            $candidate = [
                'type' => 'song',
                'songId' => (int)($song['id'] ?? 0),
                'title' => $title,
                'artist' => $this->nameFromNode($song['artist'] ?? null),
                'album' => $this->nameFromNode($song['album'] ?? null),
                'score' => $this->score($query, $title, 90, 55, 30),
            ];
            $candidate['confidence'] = $this->confidence((int)$candidate['score']);
            $candidate['reason'] = $this->reason($query, $title, 'song');
            $candidates[] = $candidate;
        }

        usort($candidates, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp((string)$a['title'], (string)$b['title']));

        return array_slice($candidates, 0, 10);
    }

    /**
     * @param list<array<string, mixed>> $albums
     * @param list<array<string, mixed>> $artists
     * @param list<array<string, mixed>> $songs
     * @return array<string, mixed>|null
     */
    private function bestMatch(array $albums, array $artists, array $songs): ?array
    {
        $candidates = array_merge($albums, $artists, $songs);
        usort($candidates, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']));
        $best = $candidates[0] ?? null;
        if ($best === null || (int)($best['score'] ?? 0) < 25) {
            return null;
        }

        $second = $candidates[1] ?? null;
        $best['ambiguous'] = $second !== null && ((int)$best['score'] - (int)($second['score'] ?? 0)) < 10;

        return $best;
    }

    /**
     * @param array<string, mixed>|null $bestMatch
     * @param list<array<string, mixed>> $albums
     * @param list<array<string, mixed>> $artists
     * @param list<array<string, mixed>> $songCandidates
     * @param list<array<string, mixed>> $songs
     */
    private function content(string $query, ?array $bestMatch, array $albums, array $artists, array $songCandidates, array $songs): string
    {
        if ($songs === [] && $albums === [] && $artists === []) {
            return 'No music matched the search.';
        }

        $lines = [];
        if ($bestMatch !== null) {
            $lines[] = $this->bestMatchText($bestMatch);
            if (($bestMatch['ambiguous'] ?? false) === true) {
                $lines[] = 'Ambiguous: the next best match is close. Ask a follow-up if the user intent is unclear.';
            }
            if (($bestMatch['type'] ?? '') === 'album') {
                $lines[] = 'Use albumId ' . (int)$bestMatch['albumId'] . ' or the full songIds list to queue the album.';
            } elseif (($bestMatch['type'] ?? '') === 'song') {
                $lines[] = 'Use songId ' . (int)$bestMatch['songId'] . ' to queue this song.';
            }
        } else {
            $lines[] = 'No high-confidence interpretation for query "' . $query . '". Review the candidates below.';
        }

        if ($albums !== []) {
            $lines[] = '';
            $lines[] = 'Album candidates:';
            foreach (array_slice($albums, 0, 5) as $album) {
                $lines[] = sprintf(
                    '- albumId %d | %s | %s | %d track(s) | songIds [%s]',
                    (int)$album['albumId'],
                    (string)$album['album'],
                    (string)$album['artist'],
                    (int)$album['trackCount'],
                    implode(', ', array_slice(array_map('intval', $album['songIds'] ?? []), 0, 30))
                );
            }
        }

        if ($songCandidates !== []) {
            $lines[] = '';
            $lines[] = 'Top songs:';
            foreach (array_slice($songCandidates, 0, 10) as $song) {
                $lines[] = sprintf(
                    '- songId %d | %s | %s | %s',
                    (int)$song['songId'],
                    (string)$song['title'],
                    (string)$song['artist'],
                    (string)$song['album']
                );
            }
        }

        if ($artists !== []) {
            $lines[] = '';
            $lines[] = 'Artist candidates:';
            foreach (array_slice($artists, 0, 5) as $artist) {
                $lines[] = sprintf(
                    '- artistId %d | %s | confidence %s',
                    (int)$artist['artistId'],
                    (string)$artist['artist'],
                    (string)$artist['confidence']
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $bestMatch
     */
    private function bestMatchText(array $bestMatch): string
    {
        return match ((string)($bestMatch['type'] ?? '')) {
            'album' => sprintf(
                'Best match: album "%s" by %s. Confidence: %s. Reason: %s.',
                (string)$bestMatch['album'],
                (string)$bestMatch['artist'],
                (string)$bestMatch['confidence'],
                (string)$bestMatch['reason']
            ),
            'artist' => sprintf(
                'Best match: artist "%s". Confidence: %s. Reason: %s.',
                (string)$bestMatch['artist'],
                (string)$bestMatch['confidence'],
                (string)$bestMatch['reason']
            ),
            'song' => sprintf(
                'Best match: song "%s" by %s. Confidence: %s. Reason: %s.',
                (string)$bestMatch['title'],
                (string)$bestMatch['artist'],
                (string)$bestMatch['confidence'],
                (string)$bestMatch['reason']
            ),
            default => 'Best match: unknown.',
        };
    }

    private function score(string $query, string $value, int $exact, int $prefix, int $contains): int
    {
        $query = $this->normalize($query);
        $value = $this->normalize($value);
        if ($query === '' || $value === '') {
            return 0;
        }
        if ($query === $value) {
            return $exact;
        }
        if (str_starts_with($value, $query)) {
            return $prefix;
        }
        if (str_contains($value, $query)) {
            return $contains;
        }

        return 0;
    }

    private function confidence(int $score): string
    {
        if ($score >= 100) {
            return 'high';
        }
        if ($score >= 60) {
            return 'medium';
        }

        return 'low';
    }

    private function reason(string $query, string $value, string $type): string
    {
        $query = $this->normalize($query);
        $value = $this->normalize($value);
        if ($query !== '' && $query === $value) {
            return 'query exactly matches ' . $type . ' name';
        }
        if ($query !== '' && str_starts_with($value, $query)) {
            return 'query matches the start of ' . $type . ' name';
        }
        if ($query !== '' && str_contains($value, $query)) {
            return 'query appears in ' . $type . ' name';
        }

        return 'result clustering suggests this ' . $type;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/^the\s+/', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return list<int>
     */
    private function albumSongIds(int $albumId): array
    {
        $this->bootstrap();

        $sql = "SELECT `id` FROM `song` WHERE `album` = ? AND `enabled` = 1 ORDER BY `disk`, `track`, `title`, `id`";
        $dbResults = Dba::read($sql, [$albumId]);
        $ids = [];
        while ($row = Dba::fetch_assoc($dbResults)) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }

    private function nameFromNode(mixed $node): string
    {
        if (is_array($node)) {
            return (string)($node['name'] ?? $node['title'] ?? '');
        }

        return (string)$node;
    }

    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        ampache_mcp_boot_ampache($this->ampacheRoot);
        $this->bootstrapped = true;
    }
}
