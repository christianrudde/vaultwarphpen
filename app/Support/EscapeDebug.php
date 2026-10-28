<?php

namespace App\Support;

/**
 * Rust's escaping for debugging (char::escape_debug_ext, Rust 1.99): backslash
 * escapes for \0, \t, \r, \n, quotes and the backslash, \u{...} for control,
 * private use, white space, default ignorable, format and unassigned
 * characters, and for grapheme extenders where Rust asks for it.
 */
final class EscapeDebug
{
    private const array ESCAPES = ["\0" => '\0', "\t" => '\t', "\r" => '\r', "\n" => '\n', '\\' => '\\\\', '"' => '\"'];

    /**
     * str::escape_debug: escapes both quotes, grapheme extenders only at the start.
     */
    public static function of(string $value): string
    {
        $escaped = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $index => $char) {
            $escaped .= self::char($char, singleQuote: true, graphemeExtender: $index === 0);
        }

        return $escaped;
    }

    /**
     * A str formatted with {:?}: in double quotes, single quotes stay, grapheme
     * extenders are escaped everywhere. A UTF-8 Path prints the same.
     */
    public static function debug(string $value): string
    {
        $escaped = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $escaped .= self::char($char, singleQuote: false, graphemeExtender: true);
        }

        return '"'.$escaped.'"';
    }

    private static function char(string $char, bool $singleQuote, bool $graphemeExtender): string
    {
        return match (true) {
            isset(self::ESCAPES[$char]) => self::ESCAPES[$char],
            $char === "'" => $singleQuote ? "\\'" : "'",
            // Printable ASCII, and two grapheme extenders Rust prints as they are.
            strlen($char) === 1 && ord($char) >= 0x20 && ord($char) <= 0x7E, $char === "\u{FF9E}", $char === "\u{FF9F}" => $char,
            preg_match('/^[\p{Cc}\p{Co}\p{White_Space}\p{Default_Ignorable_Code_Point}\p{Cf}\p{Cn}]$/u', $char) === 1,
            $graphemeExtender && preg_match('/^\p{Grapheme_Extend}$/u', $char) === 1 => sprintf('\u{%x}', mb_ord($char, 'UTF-8')),
            default => $char,
        };
    }
}
