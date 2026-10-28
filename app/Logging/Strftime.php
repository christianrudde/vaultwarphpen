<?php

namespace App\Logging;

/**
 * chrono's strftime specifiers, as LOG_TIMESTAMP_FORMAT uses them, in PHP's
 * date() format. PHP has no nanoseconds: %f and %9f end in zeros. Unknown
 * specifiers stay as they are.
 */
final class Strftime
{
    private const array SPECIFIERS = [
        'Y' => 'Y', 'C' => null, 'y' => 'y', 'm' => 'm', 'b' => 'M', 'h' => 'M', 'B' => 'F', 'd' => 'd', 'e' => null,
        'a' => 'D', 'A' => 'l', 'w' => 'w', 'u' => 'N', 'j' => null,
        'H' => 'H', 'k' => null, 'I' => 'h', 'l' => null, 'P' => 'a', 'p' => 'A', 'M' => 'i', 'S' => 's',
        'f' => 'u000', '3f' => 'v', '6f' => 'u', '9f' => 'u000', '.f' => '.u', '.3f' => '.v', '.6f' => '.u', '.9f' => '.u000',
        'z' => 'O', ':z' => 'P', 'Z' => 'T', 's' => 'U',
        'D' => 'm/d/y', 'x' => 'm/d/y', 'F' => 'Y-m-d', 'R' => 'H:i', 'T' => 'H:i:s', 'X' => 'H:i:s', 'r' => 'h:i:s A',
        'c' => 'D M j H:i:s Y', '+' => 'Y-m-d\TH:i:s.uP', 't' => "\t", 'n' => "\n", '%' => '%',
    ];

    public static function toDateFormat(string $format): string
    {
        $result = '';
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            $char = $format[$i];
            if ($char !== '%') {
                $result .= self::escape($char);

                continue;
            }

            $specifier = null;
            foreach (['.3f', '.6f', '.9f', '3f', '6f', '9f', '.f', ':z'] as $long) {
                if (substr($format, $i + 1, strlen($long)) === $long) {
                    $specifier = $long;
                    break;
                }
            }
            $specifier ??= $format[$i + 1] ?? '';
            $i += strlen($specifier);

            $converted = self::SPECIFIERS[$specifier] ?? null;
            $result .= match (true) {
                $converted !== null && in_array($specifier, ['t', 'n', '%'], true) => self::escape($converted),
                $converted !== null => $converted,
                default => self::escape("%{$specifier}"),
            };
        }

        return $result;
    }

    private static function escape(string $text): string
    {
        return (string) preg_replace('/([a-zA-Z\\\\])/', '\\\\$1', $text);
    }
}
