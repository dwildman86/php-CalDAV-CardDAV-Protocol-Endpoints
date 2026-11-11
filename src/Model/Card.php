<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * An address object resource (one .vcf file).
 */
final class Card
{
    public function __construct(
        public readonly int|string $addressBookId,
        public readonly string $uri,
        public readonly string $data,
        public readonly string $etag,
        public readonly int $size,
        public readonly int $lastModified,
    ) {
    }
}
