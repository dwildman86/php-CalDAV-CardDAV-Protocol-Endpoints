<?php

declare(strict_types=1);

namespace GhostParse\Dav\VObject;

use DateTimeImmutable;
use DateTimeZone;

/**
 * iCalendar DATE / DATE-TIME / DURATION helpers.
 */
final class DateUtil
{
    /**
     * Parse a DATE or DATE-TIME property into a Unix timestamp.
     *
     * @return array{0: int, 1: bool}|null [timestamp, isDateOnly]
     */
    public static function parseProperty(?Property $property, ?DateTimeZone $default = null): ?array
    {
        if ($property === null) {
            return null;
        }
        $value = trim(explode(',', $property->value)[0]);

        return self::parseValue($value, $property->getFirstParameter('TZID'), $default);
    }

    /**
     * @return array{0: int, 1: bool}|null [timestamp, isDateOnly]
     */
    public static function parseValue(string $value, ?string $tzid = null, ?DateTimeZone $default = null): ?array
    {
        $utc = new DateTimeZone('UTC');

        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            $tz = $default ?? $utc;
            $date = DateTimeImmutable::createFromFormat('!Ymd', $m[1] . $m[2] . $m[3], $tz);

            return $date === false ? null : [$date->getTimestamp(), true];
        }

        if (preg_match('/^(\d{8})T(\d{6})(Z?)$/i', $value, $m)) {
            if ($m[3] !== '') {
                $tz = $utc;
            } elseif ($tzid !== null) {
                $tz = self::timezone($tzid) ?? $default ?? $utc;
            } else {
                $tz = $default ?? $utc;
            }
            $date = DateTimeImmutable::createFromFormat('!Ymd\THis', $m[1] . 'T' . $m[2], $tz);

            return $date === false ? null : [$date->getTimestamp(), false];
        }

        return null;
    }

    /**
     * Parse a UTC time-range attribute value (e.g. "20240101T000000Z").
     */
    public static function parseUtc(string $value): ?int
    {
        $parsed = self::parseValue(trim($value), null, new DateTimeZone('UTC'));

        return $parsed[0] ?? null;
    }

    /**
     * Parse an RFC 5545 DURATION into seconds.
     */
    public static function parseDuration(string $value): ?int
    {
        if (!preg_match('/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim($value), $m)) {
            return null;
        }
        $seconds = (int) ($m[2] ?? 0) * 604800
            + (int) ($m[3] ?? 0) * 86400
            + (int) ($m[4] ?? 0) * 3600
            + (int) ($m[5] ?? 0) * 60
            + (int) ($m[6] ?? 0);

        return ($m[1] ?? '') === '-' ? -$seconds : $seconds;
    }

    /**
     * Resolve a TZID to a PHP time zone, including common vendor-prefixed
     * forms such as "/mozilla.org/20050126_1/Europe/Berlin".
     */
    public static function timezone(string $tzid): ?DateTimeZone
    {
        $tzid = trim($tzid, " \"");
        try {
            return new DateTimeZone($tzid);
        } catch (\Exception) {
        }
        if (preg_match('#([A-Za-z_]+/[A-Za-z0-9_+\-]+(?:/[A-Za-z0-9_+\-]+)?)$#', $tzid, $m)) {
            try {
                return new DateTimeZone($m[1]);
            } catch (\Exception) {
            }
        }

        return null;
    }
}
