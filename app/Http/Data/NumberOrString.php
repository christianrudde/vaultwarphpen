<?php

namespace App\Http\Data;

use App\Http\ApiError;
use App\Http\HttpStatus;

/**
 * util::NumberOrString: an i64 or a string, read as a number on demand.
 */
final class NumberOrString
{
    /**
     * The JSON value of an Option<NumberOrString> field; null when it is missing.
     *
     * @throws HttpStatus Anything but an integer or a string
     */
    public static function optional(Fields $fields, string $name): int|string|null
    {
        $value = $fields->value($name);

        return $value === null || is_int($value) || is_string($value) ? $value : throw new HttpStatus(422, "Field `{$name}` must be a number or a string.");
    }

    /**
     * @throws ApiError
     */
    public static function intoI64(int|string $value): int
    {
        return is_int($value) ? $value : self::parse($value, '9223372036854775807', '9223372036854775808');
    }

    /**
     * @throws ApiError
     */
    public static function intoI32(int|string $value): int
    {
        if (is_int($value)) {
            return $value >= -2_147_483_648 && $value <= 2_147_483_647 ? $value : throw ApiError::message('Number does not fit in i32');
        }

        return self::parse($value, '2147483647', '2147483648');
    }

    /**
     * Rust's integer parsing: an optional sign and decimal digits, in range.
     *
     * @throws ApiError
     */
    private static function parse(string $value, string $max, string $minAbs): int
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim(ltrim($value, '+-'), '0');
        $limit = $negative ? $minAbs : $max;
        $fits = strlen($digits) < strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) <= 0);

        if (! preg_match('/^[+-]?[0-9]+$/D', $value) || ! $fits) {
            throw ApiError::message("Can't convert to number", logMessage: 'invalid digit found in string');
        }

        return $negative && $digits === '9223372036854775808' ? PHP_INT_MIN : (int) $value;
    }
}
