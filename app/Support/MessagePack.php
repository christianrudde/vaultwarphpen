<?php

namespace App\Support;

use InvalidArgumentException;
use stdClass;

/**
 * The MessagePack encoder of rmpv's write_value, for the SignalR messages of
 * the live notifications: every value in its shortest form. Lists are
 * arrays; other PHP arrays and objects are maps, keys in their order (an
 * empty map is `new stdClass`).
 */
final class MessagePack
{
    public static function encode(mixed $value): string
    {
        return match (true) {
            $value === null => "\xc0",
            $value === false => "\xc2",
            $value === true => "\xc3",
            is_int($value) => $value < 0 ? self::negative($value) : self::unsigned($value),
            is_string($value) => self::length(strlen($value), "\xa0", 32, "\xd9", "\xda", "\xdb").$value,
            $value instanceof MessagePackExt => self::ext($value),
            is_array($value) && array_is_list($value) => self::length(count($value), "\x90", 16, null, "\xdc", "\xdd")
                .implode('', array_map(self::encode(...), $value)),
            is_array($value), $value instanceof stdClass => self::map((array) $value),
            default => throw new InvalidArgumentException('Cannot encode '.get_debug_type($value).' as MessagePack.'),
        };
    }

    /**
     * write_uint: a positive fixint, else the smallest of u8 to u64.
     */
    private static function unsigned(int $value): string
    {
        return match (true) {
            $value < 256 => ($value < 128 ? '' : "\xcc").pack('C', $value),
            $value < 65536 => "\xcd".pack('n', $value),
            $value < 4294967296 => "\xce".pack('N', $value),
            default => "\xcf".pack('J', $value),
        };
    }

    /**
     * write_sint for negative values: a negative fixint, else the smallest of i8 to i64.
     */
    private static function negative(int $value): string
    {
        return match (true) {
            $value >= -128 => ($value >= -32 ? '' : "\xd0").pack('c', $value),
            $value >= -32768 => "\xd1".pack('n', $value & 0xFFFF),
            $value >= -2147483648 => "\xd2".pack('N', $value & 0xFFFFFFFF),
            default => "\xd3".pack('J', $value),
        };
    }

    /**
     * A fix marker below the limit, else an 8-bit (where the type has one), 16-bit or 32-bit length.
     */
    private static function length(int $length, string $fix, int $fixLimit, ?string $marker8, string $marker16, string $marker32): string
    {
        return match (true) {
            $length < $fixLimit => pack('C', ord($fix) | $length),
            $marker8 !== null && $length < 256 => $marker8.pack('C', $length),
            $length < 65536 => $marker16.pack('n', $length),
            default => $marker32.pack('N', $length),
        };
    }

    /**
     * @param  array<mixed>  $map
     */
    private static function map(array $map): string
    {
        $encoded = self::length(count($map), "\x80", 16, null, "\xde", "\xdf");
        foreach ($map as $key => $value) {
            $encoded .= self::encode((string) $key).self::encode($value);
        }

        return $encoded;
    }

    /**
     * write_ext_meta: fixext for 1, 2, 4, 8 and 16 bytes, else ext 8, 16 or 32.
     */
    private static function ext(MessagePackExt $ext): string
    {
        $length = strlen($ext->data);
        $meta = match ($length) {
            1 => "\xd4",
            2 => "\xd5",
            4 => "\xd6",
            8 => "\xd7",
            16 => "\xd8",
            default => match (true) {
                $length < 256 => "\xc7".pack('C', $length),
                $length < 65536 => "\xc8".pack('n', $length),
                default => "\xc9".pack('N', $length),
            },
        };

        return $meta.pack('c', $ext->type).$ext->data;
    }
}
