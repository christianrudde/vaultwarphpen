<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * A user's favorite cipher (src/db/models/favorite.rs).
 *
 * @property string $user_uuid
 * @property string $cipher_uuid
 */
final class Favorite extends Model
{
    protected $table = 'favorites';

    protected $primaryKey = 'cipher_uuid';

    public static function isFavorite(string $cipherUuid, string $userUuid): bool
    {
        return self::query()->where('cipher_uuid', $cipherUuid)->where('user_uuid', $userUuid)->exists();
    }

    /**
     * Only a change touches the user's revision.
     *
     * @throws ApiError
     */
    public static function setFavorite(bool $favorite, string $cipherUuid, string $userUuid): void
    {
        if (self::isFavorite($cipherUuid, $userUuid) === $favorite) {
            return;
        }

        User::updateUuidRevision($userUuid);
        try {
            if ($favorite) {
                self::query()->insert(['user_uuid' => $userUuid, 'cipher_uuid' => $cipherUuid]);
            } else {
                self::query()->where('cipher_uuid', $cipherUuid)->where('user_uuid', $userUuid)->delete();
            }
        } catch (QueryException) {
            throw ApiError::message($favorite ? 'Error adding favorite' : 'Error removing favorite');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByUser(string $userUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing favorites by user');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCipher(string $cipherUuid): void
    {
        try {
            self::query()->where('cipher_uuid', $cipherUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing favorites by cipher');
        }
    }

    /**
     * @return list<string>
     */
    public static function cipherUuidsOfUser(string $userUuid): array
    {
        return array_values(self::query()->where('user_uuid', $userUuid)->pluck('cipher_uuid')->map(static fn (mixed $id): string => is_string($id) ? $id : '')->all());
    }
}
