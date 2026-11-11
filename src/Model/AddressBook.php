<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * An address book collection.
 */
final class AddressBook
{
    public function __construct(
        public readonly int|string $id,
        public readonly string $owner,
        public readonly string $uri,
        public readonly ?string $displayName = null,
        public readonly ?string $description = null,
        public readonly int $syncToken = 1,
    ) {
    }
}
