<?php

namespace App\Support;

/**
 * Random values like Vaultwarden's crypto and util modules.
 */
final class Random
{
    /**
     * util::get_uuid: a random (version 4) UUID, lower case.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * crypto::encode_random_bytes::<N>(&BASE64URL): URL-safe base64 with padding.
     *
     * @param  int<1, max>  $bytes
     */
    public static function base64Url(int $bytes): string
    {
        return strtr(base64_encode(random_bytes($bytes)), '+/', '-_');
    }

    /**
     * crypto::get_random_string_alphanum: ASCII letters and digits.
     *
     * @param  int<1, max>  $chars
     */
    public static function alphanumeric(int $chars): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $string = '';
        for ($i = 0; $i < $chars; $i++) {
            $string .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $string;
    }

    /**
     * crypto::generate_id::<N>(): lower-case hex.
     *
     * @param  int<1, max>  $bytes
     */
    public static function hex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
