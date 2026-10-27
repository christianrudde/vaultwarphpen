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
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[Tt ](\d{2}:\d{2}:\d{2})(?:\.(\d+))?\s*(?:[Zz]|UTC|(?:[+-]|\x{2212})\d{2}[:\s]*[0-5]\d)$/Diu', $value, $match)) {
            return null;
        }

        $micros = substr(str_pad($match[3] ?? '', 6, '0'), 0, 6);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', "{$match[1]} {$match[2]}.{$micros}", new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d H:i:s') === "{$match[1]} {$match[2]}" ? $date : null;
    }

    /**
     * util::format_date, like 2026-10-27T20:15:04.123456Z.
     */
    public static function format(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
