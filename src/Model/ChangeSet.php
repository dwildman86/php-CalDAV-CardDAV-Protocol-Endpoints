<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * Changes to a collection since a given sync token (RFC 6578).
 */
final class ChangeSet
{
    /**
     * @param list<string> $changed URIs of members added or modified
     * @param list<string> $deleted URIs of members removed
     */
    public function __construct(
        public readonly int $syncToken,
        public readonly array $changed = [],
        public readonly array $deleted = [],
    ) {
    }
}
