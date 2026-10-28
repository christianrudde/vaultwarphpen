<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A cipher in a collection (CollectionCipher in src/db/models/collection.rs,
 * the ciphers_collections table).
 */
final class CollectionCipher
{
    private const string TABLE = 'ciphers_collections';

    /**
     * Inserted unless it exists.
     *
     * @throws ApiError
     */
    public static function save(string $cipherUuid, string $collectionUuid): void
    {
        self::updateUsersRevision($collectionUuid);
        try {
            DB::table(self::TABLE)->insertOrIgnore(['cipher_uuid' => $cipherUuid, 'collection_uuid' => $collectionUuid]);
        } catch (QueryException) {
            throw ApiError::message('Error adding cipher to collection');
        }
    }

    /**
     * @throws ApiError
     */
    public static function delete(string $cipherUuid, string $collectionUuid): void
    {
        self::updateUsersRevision($collectionUuid);
        try {
            DB::table(self::TABLE)->where('cipher_uuid', $cipherUuid)->where('collection_uuid', $collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting cipher from collection');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCipher(string $cipherUuid): void
    {
        try {
            DB::table(self::TABLE)->where('cipher_uuid', $cipherUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing cipher from collections');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCollection(string $collectionUuid): void
    {
        try {
            DB::table(self::TABLE)->where('collection_uuid', $collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing ciphers from collection');
        }
    }

    /**
     * The collections of a cipher.
     *
     * @return list<string>
     */
    public static function collectionsOfCipher(string $cipherUuid): array
    {
        $ids = [];
        foreach (DB::table(self::TABLE)->where('cipher_uuid', $cipherUuid)->pluck('collection_uuid') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    private static function updateUsersRevision(string $collectionUuid): void
    {
        OrgCollection::findByUuid($collectionUuid)?->updateUsersRevision();
    }
}
