<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

use GhostParse\Dav\Model\ObjectMetadata;

/**
 * Validates uploaded calendar objects and vCards and extracts the metadata
 * that backends index on.
 */
final class ObjectValidator
{
    /**
     * Validate an iCalendar object resource (RFC 4791 §4.1).
     *
     * @throws ParseException
     */
    public static function calendarObject(string $data): ObjectMetadata
    {
        $root = Parser::parse($data);
        if ($root->name !== 'VCALENDAR') {
            throw new ParseException('Root component must be VCALENDAR');
        }

        $components = array_values(array_filter(
            $root->children,
            static fn (Component $c): bool => $c->name !== 'VTIMEZONE',
        ));
        if ($components === []) {
            throw new ParseException('Calendar object contains no calendar component');
        }

        $type = $components[0]->name;
        $uid = null;
        $first = null;
        $last = null;
        $hasSpan = false;
        $infinite = false;

        foreach ($components as $component) {
            if ($component->name !== $type) {
                throw new ParseException('A calendar object resource may only contain one type of component');
            }
            $componentUid = trim((string) $component->getProperty('UID')?->value);
            if ($componentUid === '') {
                throw new ParseException(sprintf('%s is missing a UID', $type));
            }
            if ($uid !== null && $componentUid !== $uid) {
                throw new ParseException('All components in a calendar object resource must share the same UID');
            }
            $uid = $componentUid;

            $span = TimeRange::span($component);
            if ($span === null) {
                continue;
            }
            $hasSpan = true;
            $first = $first === null ? $span[0] : min($first, $span[0]);
            if ($span[1] === TimeRange::INFINITE) {
                $infinite = true;
            } else {
                $last = $last === null ? $span[1] : max($last, $span[1]);
            }
        }

        if (!$hasSpan) {
            return new ObjectMetadata((string) $uid, $type);
        }

        // Pad by a day either side so floating times in any zone are covered.
        return new ObjectMetadata(
            (string) $uid,
            $type,
            $first - 86400,
            $infinite || $last === null ? null : $last + 86400,
        );
    }

    /**
     * Validate a vCard (RFC 6352 §5.1).
     *
     * @throws ParseException
     */
    public static function vcard(string $data): ObjectMetadata
    {
        $root = Parser::parse($data);
        if ($root->name !== 'VCARD') {
            throw new ParseException('Root component must be VCARD');
        }
        $uid = trim((string) $root->getProperty('UID')?->value);
        if ($uid === '') {
            throw new ParseException('vCard is missing a UID');
        }

        return new ObjectMetadata($uid);
    }
}
