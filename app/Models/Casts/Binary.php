<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A `Vec<u8>` column (BLOB, LONGBLOB, BYTEA), or an `Option<Vec<u8>>` one.
 * PostgreSQL returns streams; the model binds the value as a LOB when it
 * saves (see Model::upsertRow()).
 *
 * @implements CastsAttributes<?string, ?string>
 */
final class Binary implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if (is_resource($value)) {
            rewind($value);

            return (string) stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }
}
