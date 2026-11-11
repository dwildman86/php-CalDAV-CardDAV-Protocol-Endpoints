<?php

declare(strict_types=1);

namespace GhostParse\Dav\Xml;

/**
 * XML namespaces used by WebDAV, CalDAV and CardDAV.
 */
final class Ns
{
    public const DAV = 'DAV:';
    public const CALDAV = 'urn:ietf:params:xml:ns:caldav';
    public const CARDDAV = 'urn:ietf:params:xml:ns:carddav';
    public const CALENDARSERVER = 'http://calendarserver.org/ns/';
    public const APPLE_ICAL = 'http://apple.com/ns/ical/';
    public const GHOSTPARSE = 'urn:ghostparse:dav';

    /** Preferred prefixes used when serialising responses. */
    public const PREFIXES = [
        self::DAV => 'd',
        self::CALDAV => 'cal',
        self::CARDDAV => 'card',
        self::CALENDARSERVER => 'cs',
        self::APPLE_ICAL => 'ical',
        self::GHOSTPARSE => 'gp',
    ];

    /**
     * Build a Clark-notation name: "{namespace}local".
     */
    public static function clark(string $namespace, string $local): string
    {
        return '{' . $namespace . '}' . $local;
    }

    /**
     * Split a Clark-notation name into [namespace, local name].
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $clark): array
    {
        if (preg_match('/^\{([^}]*)\}(.+)$/', $clark, $m) === 1) {
            return [$m[1], $m[2]];
        }

        return ['', $clark];
    }
}
