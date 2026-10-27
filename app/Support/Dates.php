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
     * util::format_date, like 2026-10-27T20:15:04.123456Z.
     */
    public static function format(DateTimeInterface $date): string
    {
        return DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
