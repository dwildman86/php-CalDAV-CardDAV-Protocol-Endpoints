<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

use DateTimeZone;

/**
 * Computes the time span of calendar components and evaluates
 * CALDAV:time-range conditions (RFC 4791 §9.9).
 *
 * Recurring components are not expanded instance by instance; instead the
 * span from the first instance to the last possible instance is used. This
 * yields a superset of matching objects, which is safe for clients.
 */
final class TimeRange
{
    public const INFINITE = PHP_INT_MAX;

    /**
     * Return [start, end] for a component, end being INFINITE for unbounded
     * recurrences. Returns null when the component carries no time information.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function span(Component $component, ?DateTimeZone $tz = null): ?array
    {
        switch ($component->name) {
            case 'VEVENT':
            case 'VJOURNAL':
                $start = DateUtil::parseProperty($component->getProperty('DTSTART'), $tz);
                if ($start === null) {
                    return null;
                }
                $end = self::eventEnd($component, $start, $tz);
                break;

            case 'VTODO':
                $dtStart = DateUtil::parseProperty($component->getProperty('DTSTART'), $tz);
                $due = DateUtil::parseProperty($component->getProperty('DUE'), $tz);
                $completed = DateUtil::parseProperty($component->getProperty('COMPLETED'), $tz);
                $created = DateUtil::parseProperty($component->getProperty('CREATED'), $tz);
                $duration = $component->getProperty('DURATION');

                if ($dtStart !== null) {
                    $start = $dtStart;
                    if ($due !== null) {
                        $end = $due[0];
                    } elseif ($duration !== null) {
                        $end = $dtStart[0] + max(0, (int) DateUtil::parseDuration($duration->value));
                    } else {
                        $end = $dtStart[0];
                    }
                } elseif ($due !== null) {
                    $start = $due;
                    $end = $due[0];
                } elseif ($completed !== null || $created !== null) {
                    $start = $created ?? $completed;
                    $end = ($completed ?? $created)[0];
                } else {
                    return null;
                }
                break;

            case 'VFREEBUSY':
                $start = DateUtil::parseProperty($component->getProperty('DTSTART'), $tz);
                if ($start === null) {
                    return null;
                }
                $endProp = DateUtil::parseProperty($component->getProperty('DTEND'), $tz);
                $end = $endProp[0] ?? $start[0];
                break;

            default:
                return null;
        }

        $end = max($end, $start[0]);

        if ($component->getProperty('RRULE') !== null || $component->getProperty('RDATE') !== null) {
            $end = max($end, self::recurrenceEnd($component, $start[0], $end - $start[0], $tz));
        }

        return [$start[0], $end];
    }

    /**
     * Does the component overlap the half-open range [rangeStart, rangeEnd)?
     */
    public static function overlaps(Component $component, ?int $rangeStart, ?int $rangeEnd, ?DateTimeZone $tz = null): bool
    {
        $rangeStart ??= PHP_INT_MIN;
        $rangeEnd ??= PHP_INT_MAX;

        if ($component->name === 'VALARM') {
            // Alarm triggers are not evaluated; be inclusive.
            return true;
        }

        $span = self::span($component, $tz);
        if ($span === null) {
            // RFC 4791: a VTODO without any date matches every range; other
            // components without dates never match.
            return $component->name === 'VTODO';
        }

        [$start, $end] = $span;
        if ($start === $end) {
            return $start >= $rangeStart && $start < $rangeEnd;
        }

        return $start < $rangeEnd && $end > $rangeStart;
    }

    /**
     * Does a DATE / DATE-TIME property fall within the range?
     */
    public static function propertyInRange(Property $property, ?int $rangeStart, ?int $rangeEnd, ?DateTimeZone $tz = null): bool
    {
        $parsed = DateUtil::parseProperty($property, $tz);
        if ($parsed === null) {
            return false;
        }

        return $parsed[0] >= ($rangeStart ?? PHP_INT_MIN) && $parsed[0] < ($rangeEnd ?? PHP_INT_MAX);
    }

    /**
     * @param array{0: int, 1: bool} $start
     */
    private static function eventEnd(Component $component, array $start, ?DateTimeZone $tz): int
    {
        $dtEnd = DateUtil::parseProperty($component->getProperty('DTEND'), $tz);
        if ($dtEnd !== null) {
            return $dtEnd[0];
        }
        $duration = $component->getProperty('DURATION');
        if ($duration !== null) {
            return $start[0] + max(0, (int) DateUtil::parseDuration($duration->value));
        }

        // An all-day event without DTEND lasts one day.
        return $start[1] ? $start[0] + 86400 : $start[0];
    }

    /**
     * Conservative upper bound for the end of the last instance.
     */
    private static function recurrenceEnd(Component $component, int $start, int $length, ?DateTimeZone $tz): int
    {
        $end = $start + $length;

        foreach ($component->getProperties('RDATE') as $rdate) {
            foreach (explode(',', $rdate->value) as $value) {
                $value = trim(explode('/', $value)[0]);
                $parsed = DateUtil::parseValue($value, $rdate->getFirstParameter('TZID'), $tz);
                if ($parsed !== null) {
                    $end = max($end, $parsed[0] + $length + 86400);
                }
            }
        }

        $rrule = $component->getProperty('RRULE');
        if ($rrule === null) {
            return $end;
        }

        $parts = [];
        foreach (explode(';', strtoupper($rrule->value)) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        if (isset($parts['UNTIL'])) {
            $until = DateUtil::parseValue($parts['UNTIL'], null, $tz);
            if ($until === null) {
                return self::INFINITE;
            }

            return max($end, $until[0] + $length + 86400);
        }

        if (isset($parts['COUNT'])) {
            $hasBy = false;
            foreach (array_keys($parts) as $key) {
                if (str_starts_with($key, 'BY')) {
                    $hasBy = true;
                    break;
                }
            }
            if ($hasBy) {
                // BYxxx rules may skip periods; we cannot bound this cheaply.
                return self::INFINITE;
            }
            $unit = match ($parts['FREQ'] ?? '') {
                'SECONDLY' => 1,
                'MINUTELY' => 60,
                'HOURLY' => 3600,
                'DAILY' => 86400,
                'WEEKLY' => 7 * 86400,
                // Months / years may be skipped when the start day does not
                // exist (e.g. the 31st or 29 February), so be generous.
                'MONTHLY' => 62 * 86400,
                'YEARLY' => 8 * 366 * 86400,
                default => null,
            };
            if ($unit === null) {
                return self::INFINITE;
            }
            $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));

            return max($end, $start + (int) $parts['COUNT'] * $interval * $unit + $length + 86400);
        }

        return self::INFINITE;
    }
}
