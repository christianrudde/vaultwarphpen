<?php

namespace App\Http\Data;

use App\Http\HttpStatus;
use Illuminate\Http\Request;

/**
 * Rocket's body data guards: Json<T> and Form<T>.
 */
final class Body
{
    /**
     * Json<T>: the content type is not checked; invalid JSON is a 400.
     *
     * @throws HttpStatus
     */
    public static function json(Request $request): Fields
    {
        $decoded = json_decode($request->getContent(), false, 512, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpStatus(400, 'Invalid JSON: '.json_last_error_msg());
        }

        return Fields::of($decoded);
    }

    /**
     * Form<T> in lenient mode: an urlencoded or multipart body, field names
     * compared without case, the first value of a repeated field wins.
     *
     * @return array<string, string> Lower-case names
     *
     * @throws HttpStatus
     */
    public static function form(Request $request): array
    {
        $type = strtolower((string) $request->headers->get('Content-Type'));
        if (! str_starts_with($type, 'application/x-www-form-urlencoded') && ! str_starts_with($type, 'multipart/form-data')) {
            throw new HttpStatus(415, 'Expected a form.');
        }

        $fields = [];
        if (str_starts_with($type, 'multipart/form-data')) {
            foreach ($request->request->all() as $name => $value) {
                if (is_string($value)) {
                    $fields[strtolower((string) $name)] ??= $value;
                }
            }

            return $fields;
        }

        foreach (explode('&', $request->getContent()) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $fields[strtolower(urldecode($name))] ??= urldecode($value);
        }

        return $fields;
    }
}
