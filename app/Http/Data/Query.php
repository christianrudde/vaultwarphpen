<?php

namespace App\Http\Data;

use Illuminate\Http\Request;

/**
 * Rocket's query parameters, read from the raw query string: Symfony's
 * normalized one keeps only the last of repeated fields.
 */
final class Query
{
    /**
     * Every value of a field, percent-decoded, in order.
     *
     * @return list<string>
     */
    public static function values(Request $request, string $name): array
    {
        $query = $request->server->get('QUERY_STRING');
        $values = [];
        foreach (explode('&', is_string($query) ? $query : '') as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($pair !== '' && urldecode($key) === $name) {
                $values[] = urldecode($value);
            }
        }

        return $values;
    }

    /**
     * A field like `?<token>` or one of `?<data..>`: Rocket parses those
     * leniently, so the first of repeated values counts and the rest are not
     * even parsed. Null when the field is missing.
     */
    public static function first(Request $request, string $name): ?string
    {
        return self::values($request, $name)[0] ?? null;
    }
}
