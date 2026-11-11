<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * A calendar collection.
 */
final class Calendar
{
    /**
     * @param list<string> $components Supported component types, e.g. ['VEVENT', 'VTODO']
     */
    public function __construct(
        public readonly int|string $id,
        public readonly string $owner,
        public readonly string $uri,
        public readonly ?string $displayName = null,
        public readonly ?string $description = null,
        public readonly ?string $color = null,
        public readonly ?int $order = null,
        public readonly ?string $timezone = null,
        public readonly array $components = ['VEVENT', 'VTODO'],
        public readonly int $syncToken = 1,
    ) {
    }
}
