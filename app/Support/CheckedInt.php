<?php

namespace App\Support;

/**
 * Rust's checked i64 arithmetic: null where the result would overflow.
 */
final class CheckedInt
{
    public static function add(int $a, int $b): ?int
    {
        return ($b > 0 && $a > PHP_INT_MAX - $b) || ($b < 0 && $a < PHP_INT_MIN - $b) ? null : $a + $b;
    }

    public static function sub(int $a, int $b): ?int
    {
        return ($b < 0 && $a > PHP_INT_MAX + $b) || ($b > 0 && $a < PHP_INT_MIN + $b) ? null : $a - $b;
    }

    public static function mul(int $a, int $b): ?int
    {
        // intdiv rounds towards zero, which is the bound each case needs.
        $overflows = match (true) {
            $a === 0 || $b === 0 => false,
            $a > 0 && $b > 0 => $a > intdiv(PHP_INT_MAX, $b),
            $a < 0 && $b < 0 => $a < intdiv(PHP_INT_MAX, $b),
            $a > 0 => $b < intdiv(PHP_INT_MIN, $a),
            default => $a < intdiv(PHP_INT_MIN, $b),
        };

        return $overflows ? null : $a * $b;
    }

    /**
     * str::parse::<i32>: optional sign, decimal digits, in range.
     */
    public static function parseI32(string $value): ?int
    {
        if (! preg_match('/^([+-]?)(\d+)$/D', $value, $matches)) {
            return null;
        }
        $digits = ltrim($matches[2], '0');
        if (strlen($digits) > 10) {
            return null;
        }
        $number = (int) ($matches[1] === '-' ? "-{$digits}" : $digits);

        return $number < -2_147_483_648 || $number > 2_147_483_647 ? null : $number;
    }
}
