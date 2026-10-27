<?php

namespace App\Models\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * chrono's NaiveDateTime as Diesel stores it: UTC without a zone, on SQLite as
 * text in the `%F %T%.f` format, whose fraction has 0, 3, 6 or 9 digits.
 *
 * @implements CastsAttributes<DateTimeImmutable, DateTimeInterface>
 */
final class NaiveDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?DateTimeImmutable
    {
        return is_string($value) ? self::parse($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof DateTimeInterface ? self::format($value) : null;
    }

    /**
     * Diesel's `%F %T%.f`: the fraction in as few groups of three digits as needed.
     */
    public static function format(DateTimeInterface $date): string
    {
        $date = DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'));
        $micros = (int) $date->format('u');
        $fraction = match (true) {
            $micros === 0 => '',
            $micros % 1000 === 0 => sprintf('.%03d', intdiv($micros, 1000)),
            default => sprintf('.%06d', $micros),
        };

        return $date->format('Y-m-d H:i:s').$fraction;
    }

    /**
     * Every format the three databases return, with up to nanoseconds.
     */
    public static function parse(string $value): DateTimeImmutable
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?$/', $value, $match)) {
            throw new InvalidArgumentException("Not a timestamp: {$value}");
        }

        $micros = substr(str_pad($match[3] ?? '', 6, '0'), 0, 6);

        return new DateTimeImmutable("{$match[1]} {$match[2]}.{$micros}", new DateTimeZone('UTC'));
    }
}
