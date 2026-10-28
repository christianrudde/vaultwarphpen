<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Cron expressions as Vaultwarden's job scheduler reads them (the cron crate):
 * seconds, minutes, hours, day of month, month, day of week and an optional
 * year, or one of the @ shorthands. Days of the week count from Sunday (1).
 */
final readonly class CronSchedule
{
    private const array SHORTHANDS = [
        '@yearly' => '0 0 0 1 1 *',
        '@monthly' => '0 0 0 1 * *',
        '@weekly' => '0 0 0 * * 1',
        '@daily' => '0 0 0 * * *',
        '@hourly' => '0 0 * * * *',
    ];

    private const array MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
        'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
        'aug' => 8, 'august' => 8, 'sep' => 9, 'september' => 9, 'oct' => 10, 'october' => 10,
        'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
    ];

    private const array DAYS = [
        'sun' => 1, 'sunday' => 1, 'mon' => 2, 'monday' => 2, 'tue' => 3, 'tues' => 3, 'tuesday' => 3,
        'wed' => 4, 'wednesday' => 4, 'thu' => 5, 'thurs' => 5, 'thursday' => 5, 'fri' => 6, 'friday' => 6,
        'sat' => 7, 'saturday' => 7,
    ];

    /**
     * Allowed values of each field.
     */
    private const array RANGES = [[0, 59], [0, 59], [0, 23], [1, 31], [1, 12], [1, 7], [1970, 2100]];

    /**
     * @param  list<list<int>>  $fields  The allowed values of each field, in ascending order
     */
    private function __construct(
        private array $fields,
    ) {}

    public static function isValid(string $expression): bool
    {
        return self::parse($expression) !== null;
    }

    public static function parse(string $expression): ?self
    {
        $expression = trim($expression);
        $fields = preg_split('/\s+/', self::SHORTHANDS[$expression] ?? $expression) ?: [];
        if (count($fields) < 6 || count($fields) > 7) {
            return null;
        }

        $values = [];
        foreach (array_pad($fields, 7, '*') as $index => $field) {
            $values[] = self::field($field, $index);
        }

        return in_array(null, $values, true) ? null : new self($values);
    }

    /**
     * Schedule::after(...).next(): the first time after the given one, in whole seconds.
     */
    public function nextAfter(DateTimeImmutable $after): ?DateTimeImmutable
    {
        $start = $after->setTimezone(new DateTimeZone('UTC'))->modify('+1 second');
        $lower = array_map(intval(...), explode(' ', $start->format('Y n j G i s')));
        [$seconds, $minutes, $hours, $days, $months, $weekdays, $years] = $this->fields;

        // While every field so far equals the start, the next one may not go below it.
        foreach ($years as $year) {
            if ($year < $lower[0]) {
                continue;
            }
            $atStart = $year === $lower[0];
            foreach ($months as $month) {
                if ($atStart && $month < $lower[1]) {
                    continue;
                }
                $monthAtStart = $atStart && $month === $lower[1];
                foreach ($days as $day) {
                    if (! checkdate($month, $day, $year)) {
                        break;
                    }
                    if (($monthAtStart && $day < $lower[2]) || ! in_array(self::weekday($year, $month, $day), $weekdays, true)) {
                        continue;
                    }
                    $dayAtStart = $monthAtStart && $day === $lower[2];
                    foreach ($hours as $hour) {
                        if ($dayAtStart && $hour < $lower[3]) {
                            continue;
                        }
                        $hourAtStart = $dayAtStart && $hour === $lower[3];
                        foreach ($minutes as $minute) {
                            if ($hourAtStart && $minute < $lower[4]) {
                                continue;
                            }
                            $minuteAtStart = $hourAtStart && $minute === $lower[4];
                            foreach ($seconds as $second) {
                                if ($minuteAtStart && $second < $lower[5]) {
                                    continue;
                                }

                                return new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second), new DateTimeZone('UTC'));
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * A comma-separated list of `*`, points, ranges and names, each with an
     * optional step; `?` stands for every day.
     *
     * @return list<int>|null
     */
    private static function field(string $field, int $index): ?array
    {
        [$min, $max] = self::RANGES[$index];
        $any = in_array($index, [3, 5], true) ? '|\?' : '';
        $values = [];
        foreach (explode(',', $field) as $part) {
            if (! preg_match("/^(?:(?<all>\*{$any})|(?<from>\d+)-(?<to>\d+)|(?<point>\d+)|(?<first>[a-z]+)-(?<last>[a-z]+))(?:\/(?<step>\d+))?$|^(?<name>[a-z]+)$/i", $part, $match, PREG_UNMATCHED_AS_NULL)) {
                return null;
            }

            if ($match['name'] !== null) {
                $name = self::named($match['name'], $index);
                if ($name === null) {
                    return null;
                }
                $values[] = $name;

                continue;
            }

            if ($match['all'] !== null) {
                [$from, $to] = [$min, $max];
            } elseif ($match['point'] !== null) {
                $from = self::number($match['point']);
                // A point with a step runs up to the maximum.
                $to = $match['step'] === null ? $from : $max;
            } elseif ($match['from'] !== null && $match['to'] !== null) {
                [$from, $to] = [self::number($match['from']), self::number($match['to'])];
            } elseif ($match['first'] !== null && $match['last'] !== null) {
                [$from, $to] = [self::named($match['first'], $index), self::named($match['last'], $index)];
            } else {
                return null;
            }
            if ($from === null || $to === null || $from < $min || $to > $max || $from > $to) {
                return null;
            }

            $step = $match['step'] === null ? 1 : self::number($match['step']);
            if ($step === null || $step < 1 || $step > $max) {
                return null;
            }
            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    private static function named(string $name, int $index): ?int
    {
        return match ($index) {
            4 => self::MONTHS[strtolower($name)] ?? null,
            5 => self::DAYS[strtolower($name)] ?? null,
            default => null,
        };
    }

    /**
     * Ordinals are u32; anything longer than a year is out of range anyway.
     */
    private static function number(string $digits): ?int
    {
        $digits = ltrim($digits, '0');

        return strlen($digits) > 9 ? null : (int) $digits;
    }

    /**
     * The day of the week, from Sunday (1) to Saturday (7).
     */
    private static function weekday(int $year, int $month, int $day): int
    {
        return (int) gmdate('w', (int) gmmktime(0, 0, 0, $month, $day, $year)) + 1;
    }
}
