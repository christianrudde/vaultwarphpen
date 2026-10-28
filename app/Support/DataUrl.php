<?php

namespace App\Support;

/**
 * The data-url crate 0.3.2 (WHATWG data: URL processing), which icons.rs
 * uses for icons inlined in a page and svg-hush for images in an SVG.
 */
final readonly class DataUrl
{
    private function __construct(
        public string $type,
        public string $subtype,
        private bool $base64,
        private string $encodedBodyPlusFragment,
    ) {}

    /**
     * DataUrl::process: null when the string is not a data: URL with a comma.
     */
    public static function process(string $input): ?self
    {
        $afterColon = self::pretendParseDataUrl($input);
        if ($afterColon === null) {
            return null;
        }

        // find_comma_before_fragment
        $comma = strcspn($afterColon, ',#');
        if ($comma === strlen($afterColon) || $afterColon[$comma] !== ',') {
            return null;
        }
        [$type, $subtype, $base64] = self::parseHeader(substr($afterColon, 0, $comma));

        return new self($type, $subtype, $base64, substr($afterColon, $comma + 1));
    }

    /**
     * The body, or null when its base64 is invalid.
     */
    public function decode(): ?string
    {
        $body = $this->encodedBodyPlusFragment;
        $hash = strpos($body, '#');
        $decoded = self::percentDecode($hash === false ? $body : substr($body, 0, $hash));

        return $this->base64 ? self::forgivingBase64Decode($decoded) : $decoded;
    }

    private static function pretendParseDataUrl(string $input): ?string
    {
        $leftTrimmed = ltrim($input, self::controlsAndSpace());
        $position = 0;
        foreach (['d', 'a', 't', 'a', ':'] as $expected) {
            while (isset($leftTrimmed[$position]) && in_array($leftTrimmed[$position], ["\t", "\n", "\r"], true)) {
                $position++;
            }
            if (! isset($leftTrimmed[$position]) || strtolower($leftTrimmed[$position]) !== $expected) {
                return null;
            }
            $position++;
        }

        return rtrim(substr($leftTrimmed, $position), self::controlsAndSpace());
    }

    private static function controlsAndSpace(): string
    {
        return implode('', array_map(chr(...), range(0, 0x20)));
    }

    /**
     * parse_header: the MIME type, text/plain;charset=US-ASCII when it is
     * invalid, and whether the body is in base64.
     *
     * @return array{string, string, bool}
     */
    private static function parseHeader(string $fromColonToComma): array
    {
        $trimmed = trim($fromColonToComma, " \t\n\r");
        $withoutBase64 = self::removeBase64Suffix($trimmed);
        $mimeType = $withoutBase64 ?? $trimmed;

        $string = str_starts_with($mimeType, ';') ? 'text/plain' : '';
        $inQuery = false;
        for ($i = 0, $length = strlen($mimeType); $i < $length; $i++) {
            $byte = $mimeType[$i];
            $ord = ord($byte);
            if ($byte === "\t" || $byte === "\n" || $byte === "\r") {
                continue;
            }
            if ($ord <= 0x1F || $ord >= 0x7F || ($inQuery && in_array($byte, [' ', '"', '<', '>'], true))) {
                $string .= sprintf('%%%02X', $ord);

                continue;
            }
            if ($byte === '?') {
                $inQuery = true;
            }
            $string .= $byte;
        }

        [$type, $subtype] = self::parseMime($string) ?? ['text', 'plain'];

        return [$type, $subtype, $withoutBase64 !== null];
    }

    /**
     * The type and subtype of mime::parse, lower case; parameters are not needed here.
     *
     * @return array{string, string}|null
     */
    private static function parseMime(string $string): ?array
    {
        $httpWhitespace = " \t\n\r";
        $trimmed = trim($string, $httpWhitespace);
        $slash = strpos($trimmed, '/');
        if ($slash === false) {
            return null;
        }
        $type = substr($trimmed, 0, $slash);
        $rest = substr($trimmed, $slash + 1);
        $semicolon = strpos($rest, ';');
        $subtype = rtrim($semicolon === false ? $rest : substr($rest, 0, $semicolon), $httpWhitespace);

        $token = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';
        if (preg_match($token, $type) !== 1 || preg_match($token, $subtype) !== 1) {
            return null;
        }

        return [strtolower($type), strtolower($subtype)];
    }

    /**
     * remove_base64_suffix: the header without ";base64", ignoring tabs and
     * newlines, its case and spaces before it.
     */
    private static function removeBase64Suffix(string $s): ?string
    {
        $position = strlen($s);
        $previous = static function () use ($s, &$position): ?string {
            while ($position > 0) {
                $byte = $s[--$position];
                if ($byte !== "\t" && $byte !== "\n" && $byte !== "\r") {
                    return $byte;
                }
            }

            return null;
        };

        foreach (['4', '6', 'e', 's', 'a', 'b'] as $expected) {
            $byte = $previous();
            if ($byte === null || strtolower($byte) !== $expected) {
                return null;
            }
        }
        do {
            $byte = $previous();
        } while ($byte === ' ');

        return $byte === ';' ? substr($s, 0, $position) : null;
    }

    /**
     * decode_without_base64: percent escapes decoded, tabs and newlines dropped.
     */
    private static function percentDecode(string $encoded): string
    {
        $decoded = '';
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $byte = $encoded[$i];
            if ($byte === "\t" || $byte === "\n" || $byte === "\r") {
                continue;
            }
            if ($byte === '%' && $i + 2 < $length && ctype_xdigit($encoded[$i + 1]) && ctype_xdigit($encoded[$i + 2])) {
                $decoded .= (string) hex2bin($encoded[$i + 1].$encoded[$i + 2]);
                $i += 2;

                continue;
            }
            $decoded .= $byte;
        }

        return $decoded;
    }

    /**
     * forgiving_base64::Decoder: ASCII whitespace is skipped, padding is optional.
     */
    private static function forgivingBase64Decode(string $input): ?string
    {
        $symbols = '';
        $padding = 0;
        for ($i = 0, $length = strlen($input); $i < $length; $i++) {
            $byte = $input[$i];
            if (ctype_alnum($byte) || $byte === '+' || $byte === '/') {
                if ($padding > 0) {
                    return null;
                }
                $symbols .= $byte;
            } elseif ($byte === '=') {
                $padding++;
            } elseif (! in_array($byte, [' ', "\t", "\n", "\r", "\x0C"], true)) {
                return null;
            }
        }

        $remainder = strlen($symbols) % 4;
        $valid = match ($remainder) {
            0 => $padding === 0,
            2 => $padding === 0 || $padding === 2,
            3 => $padding === 0 || $padding === 1,
            default => false,
        };
        if (! $valid) {
            return null;
        }

        // The bits after the last whole byte are dropped, as the decoder does.
        $decoded = base64_decode($symbols.str_repeat('A', (4 - $remainder) % 4), true);
        if ($decoded === false) {
            return null;
        }

        return substr($decoded, 0, intdiv(strlen($symbols) * 6, 8));
    }
}
