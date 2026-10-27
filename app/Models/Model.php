<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\QueryException;

/**
 * A row of one of Vaultwarden's tables. Keys are UUID strings, timestamps are
 * managed like Vaultwarden does, and saving writes every column with an
 * upsert, like Diesel's `insert_into(...).on_conflict(...).do_update()`.
 */
abstract class Model extends EloquentModel
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Insert the row, or update all its columns when the key exists.
     *
     * @param  list<string>  $uniqueBy
     * @param  int  $tries  util::retry: attempts, 500 ms apart
     *
     * @throws ApiError With $errorMessage, like map_res()
     */
    protected function upsertRow(array $uniqueBy, string $errorMessage, int $tries = 1): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                static::query()->upsert([$this->rowForSaving()], $uniqueBy, array_keys($this->getAttributes()));

                break;
            } catch (QueryException) {
                if ($attempt >= $tries) {
                    throw ApiError::message($errorMessage);
                }
                usleep(500_000);
            }
        }

        $this->exists = true;
        $this->syncOriginal();
    }

    /**
     * Columns holding bytes; they are bound as LOBs, so that SQLite stores a BLOB and PostgreSQL a bytea.
     *
     * @return list<string>
     */
    protected function binaryColumns(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForSaving(): array
    {
        $row = $this->getAttributes();
        foreach ($this->binaryColumns() as $column) {
            $value = $this->getAttribute($column);
            if (is_string($value)) {
                $stream = fopen('php://memory', 'r+b');
                if ($stream !== false) {
                    fwrite($stream, $value);
                    rewind($stream);
                    $row[$column] = $stream;
                }
            }
        }

        return $row;
    }
}
