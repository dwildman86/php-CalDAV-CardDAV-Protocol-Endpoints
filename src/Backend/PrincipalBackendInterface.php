<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend;

use GhostParse\Dav\Model\Principal;

/**
 * Provides information about user principals.
 */
interface PrincipalBackendInterface
{
    public function getPrincipal(string $username): ?Principal;
}
