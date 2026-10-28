<?php

namespace App\Support;

/**
 * Rust's str::escape_debug: backslash escapes for \0, \t, \r, \n, quotes and
 * the backslash, \u{...} for characters that are not printable and for a
 * combining mark at the start.
 */
final class EscapeDebug
{
    private const array ESCAPES = ["\0" => '\0', "\t" => '\t', "\r" => '\r', "\n" => '\n', '\\' => '\\\\', '"' => '\"', "'" => "\\'"];

    public static function of(string $value): string
    {
        $escaped = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $index => $char) {
            $escaped .= self::ESCAPES[$char] ?? match (true) {
                // core::unicode::printable: control, format, private, unassigned and separator characters but the space.
                $char !== ' ' && preg_match('/^[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Cn}\p{Zl}\p{Zp}\p{Zs}]$/u', $char) === 1,
                $index === 0 && preg_match('/^[\p{Mn}\p{Me}]$/u', $char) === 1 => sprintf('\u{%x}', mb_ord($char, 'UTF-8')),
                default => $char,
            };
        }

        return $escaped;
    }
}
