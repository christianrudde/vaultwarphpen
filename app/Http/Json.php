<?php

namespace App\Http;

use Illuminate\Http\Response;
use stdClass;

/**
 * JSON as Vaultwarden writes it with serde_json: no escaped slashes or
 * Unicode, and objects built with `json!()` have their keys sorted, because
 * serde_json is built without `preserve_order` (its maps are BTreeMaps).
 * Structs that derive Serialize keep their field order; pass those unsorted.
 */
final class Json
{
    private const int FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS
        | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }

    /**
     * Sort the keys of every object, like a `json!()` value.
     */
    public static function sorted(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new stdClass;
            }
        }
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * A response with Rocket's JSON content type.
     *
     * @param  array<string, string>  $headers
     */
    public static function response(mixed $value, int $status = 200, array $headers = []): Response
    {
        return new Response(self::encode($value), $status, ['Content-Type' => 'application/json', ...$headers]);
    }
}
