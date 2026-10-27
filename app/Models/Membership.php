<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * A user's membership in an organization (Membership in
 * src/db/models/organization.rs); so far the parts accounts need.
 *
 * @property string $uuid
 * @property string $user_uuid
 * @property string $org_uuid
 * @property int $status
 * @property int $atype
 */
final class Membership extends Model
{
    public const int STATUS_REVOKED = -1;

    public const int STATUS_INVITED = 0;

    public const int STATUS_ACCEPTED = 1;

    public const int STATUS_CONFIRMED = 2;

    protected $table = 'users_organizations';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'atype' => 'integer',
            'access_all' => 'boolean',
        ];
    }

    /**
     * @throws ApiError
     */
    public static function acceptUserInvitations(string $userUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->where('status', self::STATUS_INVITED)
                ->update(['status' => self::STATUS_ACCEPTED]);
        } catch (QueryException) {
            throw ApiError::message('Error confirming invitations');
        }
    }
}
