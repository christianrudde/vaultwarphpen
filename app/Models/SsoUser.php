<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * The link of a user to an SSO identity (SsoUser in src/db/models/user.rs).
 * SSO login itself is not ported; the admin panel shows and removes links.
 *
 * @property string $user_uuid
 * @property string $identifier
 */
final class SsoUser extends Model
{
    protected $table = 'sso_users';

    protected $primaryKey = 'user_uuid';

    /**
     * The identifier of each linked user.
     *
     * @return array<string, string> By user UUID
     */
    public static function identifiers(): array
    {
        /** @var array<string, string> */
        return self::query()->pluck('identifier', 'user_uuid')->all();
    }

    /**
     * SsoUser::delete
     *
     * @throws ApiError
     */
    public static function deleteByUser(string $userUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting sso user');
        }
    }
}
