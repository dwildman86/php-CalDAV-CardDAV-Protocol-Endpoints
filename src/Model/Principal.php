<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * A user principal.
 */
final class Principal
{
    public function __construct(
        public readonly string $username,
        public readonly ?string $displayName = null,
        public readonly ?string $email = null,
    ) {
    }
}
