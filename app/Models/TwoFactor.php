<?php

namespace App\Models;

use App\Http\ApiError;
use App\Http\Json;
use App\Support\Random;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A two-factor method of a user (src/db/models/two_factor.rs). Types of 1000
 * and up are internal: challenges and the codes of protected actions.
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

    public const int TYPE_AUTHENTICATOR = 0;

    public const int TYPE_EMAIL = 1;

    public const int TYPE_DUO = 2;

    public const int TYPE_YUBIKEY = 3;

    public const int TYPE_U2F = 4;

    public const int TYPE_REMEMBER = 5;

    public const int TYPE_ORGANIZATION_DUO = 6;

    public const int TYPE_WEBAUTHN = 7;

    public const int TYPE_RECOVERY_CODE = 8;

    public const int TYPE_U2F_REGISTER_CHALLENGE = 1000;

    public const int TYPE_U2F_LOGIN_CHALLENGE = 1001;

    public const int TYPE_EMAIL_VERIFICATION_CHALLENGE = 1002;

    public const int TYPE_WEBAUTHN_REGISTER_CHALLENGE = 1003;

    public const int TYPE_WEBAUTHN_LOGIN_CHALLENGE = 1004;

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

    public static function new(string $userUuid, int $type, string $data): self
    {
        return new self([
            'uuid' => Random::uuid(),
            'user_uuid' => $userUuid,
            'atype' => $type,
            'enabled' => true,
            'data' => $data,
            'last_used' => 0,
        ]);
    }

    /**
     * TwoFactor::to_json_provider
     *
     * @return array<string, mixed>
     */
    public function toApiProvider(): array
    {
        return Json::sorted(['enabled' => $this->enabled, 'type' => $this->atype, 'object' => 'twoFactorProvider']);
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
