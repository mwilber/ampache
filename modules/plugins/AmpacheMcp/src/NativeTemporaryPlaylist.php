<?php

declare(strict_types=1);

namespace AmpacheMcp;

use Ampache\Repository\Model\LibraryItemEnum;
use Ampache\Repository\Model\Tmp_Playlist;

final class NativeTemporaryPlaylist
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
     * @param list<int> $songIds
     * @return array{id: int, session: string, added: list<int>, total: int}
     */
    public function replaceSongs(string $sessionId, array $songIds, bool $clear = true): array
    {
        $this->bootstrap();

        $playlist = Tmp_Playlist::get_from_session($sessionId);
        if ($clear) {
            $playlist->clear();
        }

        $added = [];
        foreach ($songIds as $songId) {
            $playlist->add_object($songId, LibraryItemEnum::SONG);
            $added[] = $songId;
        }

        return [
            'id' => $playlist->getId(),
            'session' => $sessionId,
            'added' => $added,
            'total' => $playlist->count_items(),
        ];
    }

    private function bootstrap(): void
    {
        if ($this->bootstrapped) {
            return;
        }
        if (!$this->isAvailable()) {
            throw new \RuntimeException('AMPACHE_ROOT must point at a local Ampache install for native temporary playlists.');
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
