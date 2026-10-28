<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A two-factor method of a user (src/db/models/two_factor.rs); so far the
 * lookups the login needs and the codes of protected actions.
 *
 * @property string $uuid
 * @property string $user_uuid
 * @property int $atype
 * @property bool $enabled
 * @property string $data
 * @property int $last_used
 */
final class TwoFactor extends Model
{
    protected $table = 'twofactor';

    public const int TYPE_EMAIL = 1;

    /**
     * TwoFactorType::ProtectedActions: the code mailed for a protected action.
     */
    public const int TYPE_PROTECTED_ACTIONS = 2000;

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'enabled' => 'boolean',
            'last_used' => 'integer',
        ];
    }

    /**
     * Every method except the internal ones (types 1000 and up).
     *
     * @return Collection<int, self>
     */
    public static function findByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->where('atype', '<', 1000)->get();
    }

    public static function findByUserAndType(string $userUuid, int $type): ?self
    {
        return self::query()->where('user_uuid', $userUuid)->where('atype', $type)->first();
    }

    /**
     * Upstream's REPLACE INTO: a user has one method of each type.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        try {
            self::query()->where('user_uuid', $this->user_uuid)->where('atype', $this->atype)->where('uuid', '!=', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error saving twofactor');
        }
        $this->upsertRow(['uuid'], 'Error saving twofactor');

        return true;
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByUser(string $userUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting twofactors');
        }
    }

    /**
     * @throws ApiError
     */
    public function deleteTwoFactor(): void
    {
        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting twofactor');
        }
    }
}
