<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

/**
 * Row writes as Diesel issues them.
 */
final class Rows
{
    /**
     * `on_conflict(key).do_nothing()` (ON DUPLICATE KEY on MySQL): a
     * duplicate is kept, but other errors, like a missing foreign row, are
     * not ignored as INSERT IGNORE would on MySQL.
     *
     * @param  array<string, mixed>  $row
     * @param  non-empty-list<non-empty-string>  $key
     */
    public static function insertUnlessDuplicate(string $table, array $row, array $key): void
    {
        DB::table($table)->upsert([$row], $key, [$key[0]]);
    }
}
