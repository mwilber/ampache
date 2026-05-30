<?php

declare(strict_types=1);

namespace AmpacheMcp;

use Ampache\Module\System\Dba;

final class PersistentQueuePlaylist
{
    private string $ampacheRoot;

    private string $playlistName;

    private string $playlistType;

    private bool $bootstrapped = false;

    public function __construct(string $ampacheRoot, string $playlistName = 'AI Queue', string $playlistType = 'public')
    {
        $this->ampacheRoot = rtrim($ampacheRoot, '/');
        $this->playlistName = trim($playlistName) !== '' ? trim($playlistName) : 'AI Queue';
        $this->playlistType = $playlistType === 'private' ? 'private' : 'public';
    }

    public function isAvailable(): bool
    {
        return $this->ampacheRoot !== '' && is_file($this->ampacheRoot . '/src/Config/Init.php');
    }

    /**
     * @param list<int> $songIds
     * @return array{id: int, name: string, type: string, user: int, username: string, added: list<int>, total: int}
     */
    public function replaceSongs(string $sessionId, array $songIds, bool $clear = true): array
    {
        $this->bootstrap();

        $user = $this->resolveUser($sessionId);
        $playlistId = $this->playlistId($user['id'], $user['username']);

        if ($clear) {
            Dba::write("DELETE FROM `playlist_data` WHERE `playlist` = ?", [$playlistId]);
        }

        $track = $this->lastTrack($playlistId);
        $added = [];
        foreach ($songIds as $songId) {
            ++$track;
            Dba::write(
                "INSERT INTO `playlist_data` (`playlist`, `object_id`, `object_type`, `track`) VALUES (?, ?, 'song', ?)",
                [$playlistId, $songId, $track]
            );
            $added[] = $songId;
        }

        Dba::write("UPDATE `playlist` SET `last_update` = ? WHERE `id` = ?", [time(), $playlistId]);

        return [
            'id' => $playlistId,
            'name' => $this->playlistName,
            'type' => $this->playlistType,
            'user' => $user['id'],
            'username' => $user['username'],
            'added' => $added,
            'total' => $this->countSongs($playlistId),
        ];
    }

    /**
     * @return array{id: int, username: string}
     */
    private function resolveUser(string $apiSessionId): array
    {
        $sql = "SELECT `username` FROM `session` WHERE `id` = ? AND `type` = 'api'";
        $dbResults = Dba::read($sql, [$apiSessionId]);
        $row = Dba::fetch_assoc($dbResults);
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') {
            throw new \RuntimeException('Unable to resolve the Ampache API user for AI Queue.');
        }

        $sql = "SELECT `id`, `username` FROM `user` WHERE `username` = ? LIMIT 1";
        $dbResults = Dba::read($sql, [$username]);
        $row = Dba::fetch_assoc($dbResults);
        $userId = (int)($row['id'] ?? 0);
        if ($userId < 1) {
            throw new \RuntimeException('Unable to resolve the Ampache user id for AI Queue.');
        }

        return [
            'id' => $userId,
            'username' => (string)($row['username'] ?? $username),
        ];
    }

    private function playlistId(int $userId, string $username): int
    {
        $sql = "SELECT `id` FROM `playlist` WHERE `name` = ? AND `user` = ? ORDER BY `id` LIMIT 1";
        $dbResults = Dba::read($sql, [$this->playlistName, $userId]);
        $row = Dba::fetch_assoc($dbResults);
        $playlistId = (int)($row['id'] ?? 0);
        if ($playlistId > 0) {
            Dba::write(
                "UPDATE `playlist` SET `username` = ?, `type` = ?, `last_update` = ? WHERE `id` = ?",
                [$username, $this->playlistType, time(), $playlistId]
            );

            return $playlistId;
        }

        $now = time();
        Dba::write(
            "INSERT INTO `playlist` (`name`, `user`, `username`, `type`, `date`, `last_update`) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->playlistName, $userId, $username, $this->playlistType, $now, $now]
        );

        $playlistId = (int)Dba::insert_id();
        if ($playlistId < 1) {
            throw new \RuntimeException('Unable to create AI Queue playlist.');
        }

        return $playlistId;
    }

    private function lastTrack(int $playlistId): int
    {
        $sql = "SELECT MAX(`track`) AS `track` FROM `playlist_data` WHERE `playlist` = ?";
        $dbResults = Dba::read($sql, [$playlistId]);
        $row = Dba::fetch_assoc($dbResults);

        return (int)($row['track'] ?? 0);
    }

    private function countSongs(int $playlistId): int
    {
        $sql = "SELECT COUNT(`id`) AS `song_count` FROM `playlist_data` WHERE `playlist` = ?";
        $dbResults = Dba::read($sql, [$playlistId]);
        $row = Dba::fetch_assoc($dbResults);

        return (int)($row['song_count'] ?? 0);
    }

    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        if (!$this->isAvailable()) {
            throw new \RuntimeException('ampache_root must point at a local Ampache install for AI Queue playlist writes.');
        }

        if (!defined('NO_SESSION')) {
            define('NO_SESSION', '1');
        }
        if (!defined('OUTDATED_DATABASE_OK')) {
            define('OUTDATED_DATABASE_OK', 1);
        }

        ob_start();
        require $this->ampacheRoot . '/src/Config/Init.php';
        ob_end_clean();

        $this->bootstrapped = true;
    }
}
