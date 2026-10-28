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

    /**
     * Pairs in order, names may repeat.
     *
     * @param  list<array{string, string}>  $pairs
     */
    public static function encodePairs(array $pairs): string
    {
        return implode('&', array_map(static fn (array $pair): string => self::component($pair[0]).'='.self::component($pair[1]), $pairs));
    }

    /**
     * form_urlencoded::parse: empty parts are skipped, a part without = has an empty value.
     *
     * @return list<array{string, string}>
     */
    public static function parse(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [self::decode($name), self::decode($value)];
        }

        return $pairs;
    }

    /**
     * Url::query_pairs_mut().extend_pairs(): the pairs after the query of the URL, before its fragment.
     *
     * @param  list<array{string, string}>  $pairs
     */
    public static function appendPairs(string $url, array $pairs): string
    {
        $parts = explode('#', $url, 2);
        $url = $parts[0];
        $fragment = $parts[1] ?? null;
        $encoded = self::encodePairs($pairs);
        $url = match (true) {
            ! str_contains($url, '?') => "{$url}?{$encoded}",
            str_ends_with($url, '?') => $url.$encoded,
            default => "{$url}&{$encoded}",
        };

        return $fragment === null ? $url : "{$url}#{$fragment}";
    }

    public static function component(string $value): string
    {
        return str_replace(['%2A', '~', '%20'], ['*', '%7E', '+'], rawurlencode($value));
    }

    /**
     * + is a space; invalid UTF-8 is replaced like String::from_utf8_lossy.
     */
    private static function decode(string $value): string
    {
        return mb_scrub(rawurldecode(str_replace('+', ' ', $value)), 'UTF-8');
    }
}
