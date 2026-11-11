<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * Metadata extracted from a calendar object or vCard before it is stored.
 *
 * Occurrence values are Unix timestamps. A null firstOccurrence means the
 * object has no time information; a null lastOccurrence means it recurs
 * forever (or has no time information).
 */
final class ObjectMetadata
{
    public function __construct(
        public readonly string $uid,
        public readonly ?string $componentType = null,
        public readonly ?int $firstOccurrence = null,
        public readonly ?int $lastOccurrence = null,
    ) {
    }
}
