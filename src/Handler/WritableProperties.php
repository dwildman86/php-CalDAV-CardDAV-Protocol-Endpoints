<?php

declare(strict_types=1);

namespace GhostParse\Dav\Handler;

use DOMElement;
use GhostParse\Dav\Xml\Ns;
use GhostParse\Dav\Xml\XmlUtil;

/**
 * Maps writable DAV properties onto backend property keys.
 */
final class WritableProperties
{
    private const CALENDAR = [
        '{DAV:}displayname' => 'displayName',
        '{' . Ns::CALDAV . '}calendar-description' => 'description',
        '{' . Ns::CALDAV . '}calendar-timezone' => 'timezone',
        '{' . Ns::APPLE_ICAL . '}calendar-color' => 'color',
        '{' . Ns::APPLE_ICAL . '}calendar-order' => 'order',
    ];

    private const ADDRESS_BOOK = [
        '{DAV:}displayname' => 'displayName',
        '{' . Ns::CARDDAV . '}addressbook-description' => 'description',
    ];

    /**
     * Properties that clients commonly set but that we accept and ignore
     * rather than failing the whole request.
     */
    private const IGNORED = [
        '{' . Ns::CALDAV . '}schedule-calendar-transp',
        '{' . Ns::CALENDARSERVER . '}calendar-transp',
    ];

    public const SUPPORTED_COMPONENTS = ['VEVENT', 'VTODO', 'VJOURNAL', 'VFREEBUSY'];

    /**
     * @return array<string, string> clark name => backend key
     */
    public static function calendarMap(): array
    {
        return self::CALENDAR;
    }

    /**
     * @return array<string, string> clark name => backend key
     */
    public static function addressBookMap(): array
    {
        return self::ADDRESS_BOOK;
    }

    public static function isIgnored(string $clark): bool
    {
        return in_array($clark, self::IGNORED, true);
    }

    /**
     * Read a property value from its XML element.
     */
    public static function readValue(string $key, ?DOMElement $element): mixed
    {
        if ($element === null) {
            return null;
        }
        $text = $element->textContent;
        if ($key === 'order') {
            return is_numeric(trim($text)) ? (int) trim($text) : null;
        }

        return $text;
    }

    /**
     * Read CALDAV:supported-calendar-component-set.
     *
     * @return list<string>
     */
    public static function readComponents(DOMElement $element): array
    {
        $components = [];
        foreach (XmlUtil::children($element, '{' . Ns::CALDAV . '}comp') as $comp) {
            $name = strtoupper($comp->getAttribute('name'));
            if (in_array($name, self::SUPPORTED_COMPONENTS, true)) {
                $components[] = $name;
            }
        }

        return array_values(array_unique($components));
    }

    /**
     * Collect initial properties from a DAV:set/DAV:prop block used by
     * MKCALENDAR or extended MKCOL.
     *
     * @param array<string, string> $map
     * @return array<string, mixed>
     */
    public static function collectInitial(?DOMElement $prop, array $map, bool $calendar): array
    {
        $properties = [];
        if ($prop === null) {
            return $properties;
        }
        foreach (XmlUtil::children($prop) as $element) {
            $clark = XmlUtil::clark($element);
            if (isset($map[$clark])) {
                $properties[$map[$clark]] = self::readValue($map[$clark], $element);
            } elseif ($calendar && $clark === '{' . Ns::CALDAV . '}supported-calendar-component-set') {
                $components = self::readComponents($element);
                if ($components !== []) {
                    $properties['components'] = $components;
                }
            }
        }

        return $properties;
    }
}
