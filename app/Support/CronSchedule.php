<?php

namespace App\Support;

/**
 * Cron expressions as Vaultwarden's job scheduler reads them (the cron crate):
 * seconds, minutes, hours, day of month, month, day of week and an optional
 * year, or one of the @ shorthands.
 */
final class CronSchedule
{
    private const array SHORTHANDS = ['@yearly', '@annually', '@monthly', '@weekly', '@daily', '@hourly'];

    private const array MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    private const array DAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Allowed values of each field.
     */
    private const array RANGES = [[0, 59], [0, 59], [0, 23], [1, 31], [1, 12], [1, 7], [1970, 2100]];

    public static function isValid(string $expression): bool
    {
        $expression = trim($expression);
        if (in_array(strtolower($expression), self::SHORTHANDS, true)) {
            return true;
        }

        $fields = preg_split('/\s+/', $expression) ?: [];
        if (count($fields) < 6 || count($fields) > 7) {
            return false;
        }

        foreach ($fields as $index => $field) {
            if (! self::isValidField($field, $index)) {
                return false;
            }
        }

        return true;
    }

    private static function isValidField(string $field, int $index): bool
    {
        foreach (explode(',', $field) as $part) {
            [$range, $step] = array_pad(explode('/', $part, 2), 2, null);
            if ($step !== null && (! ctype_digit($step) || (int) $step === 0)) {
                return false;
            }
            if ($range === '*' || ($range === '?' && in_array($index, [3, 5], true))) {
                continue;
            }

            $bounds = explode('-', (string) $range);
            if (count($bounds) > 2) {
                return false;
            }
            $values = array_map(static fn (string $value): ?int => self::value($value, $index), $bounds);
            if (in_array(null, $values, true) || (count($values) === 2 && $values[0] > $values[1])) {
                return false;
            }
        }

        return true;
    }

    private static function value(string $value, int $index): ?int
    {
        $names = match ($index) {
            4 => self::MONTHS,
            5 => self::DAYS,
            default => [],
        };
        // Names may be abbreviated to three letters (Mon, JAN) or written out.
        foreach ($names as $position => $name) {
            if (in_array(strtolower($value), [$name, substr($name, 0, 3)], true)) {
                return $position + 1;
            }
        }

        if (! ctype_digit($value)) {
            return null;
        }

        [$min, $max] = self::RANGES[$index];

        return (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
    }
}
