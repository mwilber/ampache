<?php

declare(strict_types=0);

/**
 * Ampache MCP plugin metadata.
 *
 * This file follows Ampache's external plugin layout:
 * modules/plugins/AmpacheMcp/AmpacheMcp.plugin.php
 *
 * The MCP HTTP endpoint is intentionally self-contained in public/index.php so
 * it can be exposed by a web server without patching Ampache core routes.
 */

namespace Ampache\Plugin;

use Ampache\Repository\Model\User;

class AmpacheAmpacheMcp extends AmpachePlugin
{
    public string $name = 'AmpacheMcp';

    public string $categories = 'plugins';

    public string $description = 'Model Context Protocol endpoint for Ampache search and temporary playlists';

    public string $url = 'https://ampache.org';

    public string $version = '000001';

    public string $min_ampache = '370021';

    public string $max_ampache = '999999';

    public function __construct()
    {
        $this->description = T_('Model Context Protocol endpoint for Ampache search and temporary playlists');
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function upgrade(): bool
    {
        return true;
    }

    public function load(User $user): bool
    {
        unset($user);

        return true;
    }
}
