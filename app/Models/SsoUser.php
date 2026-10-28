<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * The link of a user to an SSO identity (SsoUser in src/db/models/user.rs):
 * the provider's issuer and subject.
 *
 * @property string $user_uuid
 * @property string $identifier
 */
final class SsoUser extends Model
{
    protected $table = 'sso_users';

    protected $primaryKey = 'user_uuid';

    public static function new(string $userUuid, string $identifier): self
    {
        $ssoUser = new self;
        $ssoUser->user_uuid = $userUuid;
        $ssoUser->identifier = $identifier;

        return $ssoUser;
    }

    /**
     * A plain insert, like Vaultwarden's: a second link of the user fails.
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        try {
            self::query()->insert(['user_uuid' => $this->user_uuid, 'identifier' => $this->identifier]);
        } catch (QueryException) {
            throw ApiError::message('Error saving SSO user');
        }
        $this->exists = true;

        return true;
    }

    /**
     * SsoUser::find_by_identifier: the user linked to the identity.
     *
     * @return array{User, self}|null
     */
    public static function findByIdentifier(string $identifier): ?array
    {
        $ssoUser = self::query()->where('identifier', $identifier)->first();
        $user = $ssoUser === null ? null : User::findByUuid($ssoUser->user_uuid);

        return $ssoUser === null || $user === null ? null : [$user, $ssoUser];
    }

    /**
     * SsoUser::find_by_mail: the user with the address, and the identity it is linked to, if any.
     *
     * @return array{User, ?self}|null
     */
    public static function findByMail(string $email): ?array
    {
        $user = User::findByMail($email);

        return $user === null ? null : [$user, self::query()->where('user_uuid', $user->uuid)->first()];
    }

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
