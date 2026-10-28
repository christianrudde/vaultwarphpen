<?php

namespace App\Support;

/**
 * data_encoding's BASE32: RFC 4648, upper case, padded with "=". Decoding is
 * strict like data_encoding: no other characters, whole blocks, canonical
 * padding and unused trailing bits that are zero.
 */
final class Base32
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        $encoded = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($bytes) as $byte) {
            $buffer = (($buffer << 8) | ord($byte)) & 0xFFFF;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }
        }
        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        if ($encoded === '') {
            return '';
        }

        return str_pad($encoded, intdiv(strlen($encoded) + 7, 8) * 8, '=');
    }

    /**
     * Null when the text is no canonical BASE32.
     */
    public static function decode(string $text): ?string
    {
        if (strlen($text) % 8 !== 0) {
            return null;
        }
        $data = rtrim($text, '=');
        // Only these amounts of padding end a block of 1 to 4 bytes.
        if (! in_array(strlen($text) - strlen($data), [0, 1, 3, 4, 6], true) || ! preg_match('/^[A-Z2-7]*$/D', $data)) {
            return null;
        }

        $bytes = '';
        $buffer = 0;
        $bits = 0;
        foreach (str_split($data) as $char) {
            $buffer = (($buffer << 5) | (int) strpos(self::ALPHABET, $char)) & 0xFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        // The bits left over must be zero.
        if ($bits > 0 && ($buffer & ((1 << $bits) - 1)) !== 0) {
            return null;
        }

        return $bytes;
    }
}
