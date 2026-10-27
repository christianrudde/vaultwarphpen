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
     * A required field like `?<token>`: null when it is missing or repeated,
     * where Rocket forwards the request.
     */
    public static function single(Request $request, string $name): ?string
    {
        $values = self::values($request, $name);

        return count($values) === 1 ? $values[0] : null;
    }
}
