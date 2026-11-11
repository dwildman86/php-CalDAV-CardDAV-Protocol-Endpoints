<?php

declare(strict_types=1);

namespace GhostParse\Dav\Model;

/**
 * A calendar object resource (one .ics file).
 */
final class CalendarObject
{
    public function __construct(
        public readonly int|string $calendarId,
        public readonly string $uri,
        public readonly string $data,
        public readonly string $etag,
        public readonly int $size,
        public readonly int $lastModified,
        public readonly ?string $componentType = null,
    ) {
    }
}
