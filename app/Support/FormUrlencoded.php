<?php

namespace App\Support;

/**
 * application/x-www-form-urlencoded as the url crate (form_urlencoded) and
 * serde_urlencoded write it: letters, digits and *-._ stay, a space becomes
 * + and every other byte is percent-encoded in upper case.
 */
final class FormUrlencoded
{
    /**
     * @param  array<string, string>  $pairs
     */
    public static function encode(array $pairs): string
    {
        $parts = [];
        foreach ($pairs as $name => $value) {
            $parts[] = self::component($name).'='.self::component($value);
        }

        return implode('&', $parts);
    }

    public static function component(string $value): string
    {
        return str_replace(['%2A', '~', '%20'], ['*', '%7E', '+'], rawurlencode($value));
    }
}
