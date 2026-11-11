<?php

declare(strict_types=1);

namespace GhostParse\Dav\Backend\Pdo;

use GhostParse\Dav\Backend\CalendarBackendInterface;
use GhostParse\Dav\Model\Calendar;
use GhostParse\Dav\Model\CalendarObject;
use GhostParse\Dav\Model\ChangeSet;
use GhostParse\Dav\Model\ObjectMetadata;
use PDO;

/**
 * PDO (SQLite / MySQL / PostgreSQL) calendar storage.
 */
final class PdoCalendarBackend extends AbstractPdoBackend implements CalendarBackendInterface
{
    private const FIELD_MAP = [
        'displayName' => 'displayname',
        'description' => 'description',
        'color' => 'color',
        'order' => 'sortorder',
        'timezone' => 'timezone',
        'components' => 'components',
    ];

    private const OBJECT_COLUMNS = 'calendarid, uri, data, etag, size, lastmodified, componenttype';

    public function getCalendarsForUser(string $username): array
    {
        $rows = $this->query(
            sprintf('SELECT * FROM %s WHERE owner = ? ORDER BY sortorder, id', $this->table('calendars')),
            [$username],
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): Calendar => $this->hydrateCalendar($row), $rows);
    }

    public function getCalendar(string $username, string $uri): ?Calendar
    {
        $row = $this->query(
            sprintf('SELECT * FROM %s WHERE owner = ? AND uri = ?', $this->table('calendars')),
            [$username, $uri],
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateCalendar($row);
    }

    public function createCalendar(string $username, string $uri, array $properties = []): void
    {
        $values = $this->mapProperties($properties + ['components' => ['VEVENT', 'VTODO']]);
        $values['owner'] = $username;
        $values['uri'] = $uri;
        $values['synctoken'] = 1;

        $columns = array_keys($values);
        $this->query(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->table('calendars'),
                implode(', ', $columns),
                implode(', ', array_fill(0, count($columns), '?')),
            ),
            array_values($values),
        );
    }

    public function updateCalendar(int|string $calendarId, array $properties): void
    {
        $values = $this->mapProperties($properties);
        if ($values === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($values)));
        $params = array_values($values);
        $params[] = $calendarId;
        $this->query(sprintf('UPDATE %s SET %s WHERE id = ?', $this->table('calendars'), $sets), $params);
    }

    public function deleteCalendar(int|string $calendarId): void
    {
        $this->transaction(function () use ($calendarId): void {
            $this->query(sprintf('DELETE FROM %s WHERE calendarid = ?', $this->table('calendarobjects')), [$calendarId]);
            $this->query(sprintf('DELETE FROM %s WHERE calendarid = ?', $this->table('calendarchanges')), [$calendarId]);
            $this->query(sprintf('DELETE FROM %s WHERE id = ?', $this->table('calendars')), [$calendarId]);
        });
    }

    public function getCalendarObjects(int|string $calendarId): array
    {
        $rows = $this->query(
            sprintf('SELECT %s FROM %s WHERE calendarid = ? ORDER BY id', self::OBJECT_COLUMNS, $this->table('calendarobjects')),
            [$calendarId],
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): CalendarObject => $this->hydrateObject($row), $rows);
    }

    public function getCalendarObject(int|string $calendarId, string $uri): ?CalendarObject
    {
        $row = $this->query(
            sprintf('SELECT %s FROM %s WHERE calendarid = ? AND uri = ?', self::OBJECT_COLUMNS, $this->table('calendarobjects')),
            [$calendarId, $uri],
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrateObject($row);
    }

    public function findCalendarObjectUriByUid(int|string $calendarId, string $uid): ?string
    {
        $uri = $this->query(
            sprintf('SELECT uri FROM %s WHERE calendarid = ? AND uid = ?', $this->table('calendarobjects')),
            [$calendarId, $uid],
        )->fetchColumn();

        return $uri === false ? null : (string) $uri;
    }

    public function queryCalendarObjects(int|string $calendarId, ?string $componentType, ?int $start, ?int $end): array
    {
        $sql = sprintf('SELECT %s FROM %s WHERE calendarid = ?', self::OBJECT_COLUMNS, $this->table('calendarobjects'));
        $params = [$calendarId];

        if ($componentType !== null) {
            $sql .= ' AND componenttype = ?';
            $params[] = strtoupper($componentType);
        }
        if ($end !== null) {
            $sql .= ' AND (firstoccurrence IS NULL OR firstoccurrence < ?)';
            $params[] = $end;
        }
        if ($start !== null) {
            $sql .= ' AND (lastoccurrence IS NULL OR lastoccurrence >= ?)';
            $params[] = $start;
        }
        $sql .= ' ORDER BY id';

        $rows = $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn (array $row): CalendarObject => $this->hydrateObject($row), $rows);
    }

    public function createCalendarObject(int|string $calendarId, string $uri, string $data, ObjectMetadata $metadata): string
    {
        $etag = self::etag($data);
        $this->transaction(function () use ($calendarId, $uri, $data, $metadata, $etag): void {
            $this->query(
                sprintf(
                    'INSERT INTO %s (calendarid, uri, data, etag, size, lastmodified, componenttype, uid, firstoccurrence, lastoccurrence)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    $this->table('calendarobjects'),
                ),
                [$calendarId, $uri, $data, $etag, strlen($data), time(), $metadata->componentType, $metadata->uid, $metadata->firstOccurrence, $metadata->lastOccurrence],
            );
            $this->recordChange('calendars', 'calendarchanges', 'calendarid', $calendarId, $uri, self::OP_ADD);
        });

        return $etag;
    }

    public function updateCalendarObject(int|string $calendarId, string $uri, string $data, ObjectMetadata $metadata): string
    {
        $etag = self::etag($data);
        $this->transaction(function () use ($calendarId, $uri, $data, $metadata, $etag): void {
            $this->query(
                sprintf(
                    'UPDATE %s SET data = ?, etag = ?, size = ?, lastmodified = ?, componenttype = ?, uid = ?, firstoccurrence = ?, lastoccurrence = ?
                     WHERE calendarid = ? AND uri = ?',
                    $this->table('calendarobjects'),
                ),
                [$data, $etag, strlen($data), time(), $metadata->componentType, $metadata->uid, $metadata->firstOccurrence, $metadata->lastOccurrence, $calendarId, $uri],
            );
            $this->recordChange('calendars', 'calendarchanges', 'calendarid', $calendarId, $uri, self::OP_MODIFY);
        });

        return $etag;
    }

    public function deleteCalendarObject(int|string $calendarId, string $uri): void
    {
        $this->transaction(function () use ($calendarId, $uri): void {
            $this->query(sprintf('DELETE FROM %s WHERE calendarid = ? AND uri = ?', $this->table('calendarobjects')), [$calendarId, $uri]);
            $this->recordChange('calendars', 'calendarchanges', 'calendarid', $calendarId, $uri, self::OP_DELETE);
        });
    }

    public function getChangesSince(int|string $calendarId, int $syncToken): ?ChangeSet
    {
        return $this->changesSince('calendars', 'calendarchanges', 'calendarid', $calendarId, $syncToken);
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function mapProperties(array $properties): array
    {
        $values = [];
        foreach ($properties as $key => $value) {
            if (!isset(self::FIELD_MAP[$key])) {
                continue;
            }
            if ($key === 'components') {
                $value = implode(',', array_map('strtoupper', (array) $value));
            } elseif ($key === 'order') {
                $value = $value === null || $value === '' ? null : (int) $value;
            } elseif ($value !== null) {
                $value = (string) $value;
            }
            $values[self::FIELD_MAP[$key]] = $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCalendar(array $row): Calendar
    {
        $components = array_values(array_filter(array_map('trim', explode(',', (string) $row['components']))));

        return new Calendar(
            id: is_numeric($row['id']) ? (int) $row['id'] : (string) $row['id'],
            owner: (string) $row['owner'],
            uri: (string) $row['uri'],
            displayName: $row['displayname'] !== null ? (string) $row['displayname'] : null,
            description: $row['description'] !== null ? (string) $row['description'] : null,
            color: $row['color'] !== null ? (string) $row['color'] : null,
            order: $row['sortorder'] !== null ? (int) $row['sortorder'] : null,
            timezone: $row['timezone'] !== null ? self::readData($row['timezone']) : null,
            components: $components !== [] ? $components : ['VEVENT', 'VTODO'],
            syncToken: (int) $row['synctoken'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateObject(array $row): CalendarObject
    {
        return new CalendarObject(
            calendarId: is_numeric($row['calendarid']) ? (int) $row['calendarid'] : (string) $row['calendarid'],
            uri: (string) $row['uri'],
            data: self::readData($row['data']),
            etag: (string) $row['etag'],
            size: (int) $row['size'],
            lastModified: (int) $row['lastmodified'],
            componentType: $row['componenttype'] !== null ? (string) $row['componenttype'] : null,
        );
    }
}
