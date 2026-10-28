<?php

namespace App\Auth\Webauthn;

/**
 * base64urlsafedata 0.5.5: binary data that JSON carries as base64url
 * without padding. Reading forgives the other alphabets and padding (each
 * strictly, as the base64 crate decodes) and a list of byte values.
 */
final class Base64UrlSafe
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * The bytes, or null when the value is none of the accepted forms.
     */
    public static function decode(mixed $value): ?string
    {
        if (is_array($value) && array_is_list($value)) {
            $bytes = '';
            foreach ($value as $byte) {
                if (! is_int($byte) || $byte < 0 || $byte > 255) {
                    return null;
                }
                $bytes .= chr($byte);
            }

            return $bytes;
        }
        if (! is_string($value)) {
            return null;
        }

        // URL_SAFE_NO_PAD, URL_SAFE, STANDARD, STANDARD_NO_PAD, in that order.
        foreach ([['-_', false], ['-_', true], ['+/', true], ['+/', false]] as [$alphabet, $padded]) {
            $bytes = self::strict($value, $alphabet, $padded);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * Canonical encodings only: no stray characters, padding exactly as the
     * variant wants it and no bits set beyond the data.
     */
    private static function strict(string $value, string $alphabet, bool $padded): ?string
    {
        if (preg_match('/^[A-Za-z0-9'.preg_quote($alphabet, '/').']*={0,2}$/D', $value) !== 1) {
            return null;
        }
        $body = rtrim($value, '=');
        if (! $padded && $body !== $value) {
            return null;
        }
        $bytes = base64_decode(strtr($body, $alphabet, '+/'), true);
        if ($bytes === false) {
            return null;
        }
        $canonical = strtr(base64_encode($bytes), '+/', $alphabet);

        return ($padded ? $canonical : rtrim($canonical, '=')) === $value ? $bytes : null;
    }
}
