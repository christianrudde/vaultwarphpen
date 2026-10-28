<?php

namespace App\Support;

use stdClass;

/**
 * Arbitrary JSON values the way serde_json::Value handles them: objects stay
 * objects (an empty one is not an empty list), and LowerCase<Value>
 * lower-cases the first letter of every key.
 */
final class JsonValue
{
    /**
     * Decode keeping objects as stdClass; null when the text is no JSON.
     *
     * @return array{bool, mixed} Whether it parsed, and the value
     */
    public static function decode(string $json): array
    {
        $value = json_decode($json, false, 512, JSON_BIGINT_AS_STRING);

        return [json_last_error() === JSON_ERROR_NONE, $value];
    }

    /**
     * Objects become arrays with lower-cased first letters (util::convert_json_key_lcase_first);
     * "ssn" in any case stays "ssn". Empty objects stay objects.
     */
    public static function lowercaseKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $key = (string) $key;
                $key = strtolower($key) === 'ssn' ? 'ssn' : mb_strtolower(mb_substr($key, 0, 1)).mb_substr($key, 1);
                $result[$key] = self::lowercaseKeys($item);
            }

            return $result === [] ? new stdClass : $result;
        }

        return is_array($value) ? array_map(self::lowercaseKeys(...), $value) : $value;
    }

    /**
     * util::convert_json_key_lcase_first for values built here: the keys of
     * every object, as stdClass or array, with a lower-cased first letter.
     */
    public static function lowercaseFirstKeys(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new stdClass;
            }
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::lowercaseFirstKeys(...), $value);
        }

        $result = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            $key = strtolower($key) === 'ssn' ? 'ssn' : mb_strtolower(mb_substr($key, 0, 1)).mb_substr($key, 1);
            $result[$key] = self::lowercaseFirstKeys($item);
        }

        return $result;
    }

    /**
     * Objects as arrays, keeping empty ones as objects.
     */
    public static function toArrays(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $result = array_map(self::toArrays(...), get_object_vars($value));

            return $result === [] ? new stdClass : $result;
        }

        return is_array($value) ? array_map(self::toArrays(...), $value) : $value;
    }

    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ! array_is_list($value));
    }

    /**
     * util::validate_and_format_date: an RFC 3339 timestamp with microseconds,
     * keeping its offset (Z for UTC); the epoch when it is none. Like chrono,
     * this takes t or a space as separator, z, and up to 23:59 of offset.
     */
    public static function formatRfc3339(string $date): string
    {
        $epoch = '1970-01-01T00:00:00.000000Z';
        if (! preg_match('/^(\d{4}-\d{2}-\d{2})[Tt ](\d{2}:\d{2}:\d{2})(?:\.(\d+))?(?:([Zz])|([+-]|\x{2212})(\d{2}):(\d{2}))$/Du', $date, $match, PREG_UNMATCHED_AS_NULL)) {
            return $epoch;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$match[1]} {$match[2]}");
        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== "{$match[1]} {$match[2]}") {
            return $epoch;
        }

        $micros = substr(str_pad($match[3] ?? '', 6, '0'), 0, 6);
        $offset = 'Z';
        [, , , , , $sign, $hours, $minutes] = $match;
        if ($sign !== null && $hours !== null && $minutes !== null) {
            if ((int) $hours > 23 || (int) $minutes > 59) {
                return $epoch;
            }
            if ($hours !== '00' || $minutes !== '00') {
                $offset = ($sign === '+' ? '+' : '-')."{$hours}:{$minutes}";
            }
        }

        return "{$match[1]}T{$match[2]}.{$micros}{$offset}";
    }
}
