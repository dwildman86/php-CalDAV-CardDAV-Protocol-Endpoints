<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend;

use GhostParse\Dav\Model\Calendar;
use GhostParse\Dav\Model\CalendarObject;
use GhostParse\Dav\Model\ChangeSet;
use GhostParse\Dav\Model\ObjectMetadata;

/**
 * Storage for calendars and calendar objects.
 *
 * Calendar property arrays use the keys: displayName, description, color,
 * order, timezone and components (list of component names).
 */
interface CalendarBackendInterface
{
    /**
     * @return list<Calendar>
     */
    public function getCalendarsForUser(string $username): array;

    public function getCalendar(string $username, string $uri): ?Calendar;

    /**
     * @param array<string, mixed> $properties
     */
    public function createCalendar(string $username, string $uri, array $properties = []): void;

    /**
     * @param array<string, mixed> $properties Only the keys present are updated; null clears a value.
     */
    public function updateCalendar(int|string $calendarId, array $properties): void;

    public function deleteCalendar(int|string $calendarId): void;

    /**
     * @return list<CalendarObject>
     */
    public function getCalendarObjects(int|string $calendarId): array;

    public function getCalendarObject(int|string $calendarId, string $uri): ?CalendarObject;

    public function findCalendarObjectUriByUid(int|string $calendarId, string $uid): ?string;

    /**
     * Return candidate objects for a calendar-query. Implementations may
     * return a superset; the server applies the exact filter afterwards.
     *
     * @return list<CalendarObject>
     */
    public function queryCalendarObjects(int|string $calendarId, ?string $componentType, ?int $start, ?int $end): array;

    /**
     * @return string The new ETag
     */
    public function createCalendarObject(int|string $calendarId, string $uri, string $data, ObjectMetadata $metadata): string;

    /**
     * @return string The new ETag
     */
    public function updateCalendarObject(int|string $calendarId, string $uri, string $data, ObjectMetadata $metadata): string;

    public function deleteCalendarObject(int|string $calendarId, string $uri): void;

    /**
     * Return the changes since the given token, or null if the token is unknown.
     */
    public function getChangesSince(int|string $calendarId, int $syncToken): ?ChangeSet;
}
