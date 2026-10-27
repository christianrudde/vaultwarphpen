<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Vaultwarden's timestamps: naive UTC in the database, RFC 3339 with
 * microseconds in the API.
 */
final class Dates
{
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * chrono's NaiveDateTime::parse_from_str(value, "%+"): a relaxed RFC 3339
     * timestamp (t or a space as separator, spaces before the offset, UTC as
     * offset, no colon needed in it), whose offset is checked but not applied.
     */
    public static function parseNaive(string $value): ?DateTimeImmutable
    {
        return self::parseRelaxed($value)[0] ?? null;
    }

    /**
     * A DateTime<Utc> as serde reads it: the same relaxed RFC 3339, with the
     * offset applied.
     */
    public static function parseUtc(string $value): ?DateTimeImmutable
    {
        $parsed = self::parseRelaxed($value);
        if ($parsed === null) {
            return null;
        }
        [$date, $offsetSeconds] = $parsed;

        // FixedOffset takes less than a day either way.
        return abs($offsetSeconds) < 86_400 ? $date->modify(($offsetSeconds >= 0 ? '-' : '+').abs($offsetSeconds).' seconds') : null;
    }

    /**
     * @return array{DateTimeImmutable, int}|null The local time and the offset in seconds
     */
    private static function parseRelaxed(string $value): ?array
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[Tt ](\d{2}:\d{2}:\d{2})(?:\.(\d+))?\s*(?:[Zz]|UTC|(?<sign>[+-]|\x{2212})(?<hours>\d{2})[:\s]*(?<minutes>[0-5]\d))$/Diu', $value, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        $micros = substr(str_pad($match[3] ?? '', 6, '0'), 0, 6);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', "{$match[1]} {$match[2]}.{$micros}", new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== "{$match[1]} {$match[2]}") {
            return null;
        }

        ['sign' => $sign, 'hours' => $hours, 'minutes' => $minutes] = $match;
        $offset = $sign === null || $hours === null || $minutes === null ? 0 : ((int) $hours * 3600 + (int) $minutes * 60) * ($sign === '+' ? 1 : -1);

        return [$date, $offset];
    }

    /**
     * util::format_date, like 2026-10-27T20:15:04.123456Z.
     */
    public static function format(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
