<?php

namespace App\Models;

use App\Auth\Webauthn\CoseKey;
use App\Auth\Webauthn\Credential;
use App\Auth\Webauthn\Registrations;
use App\Auth\Webauthn\U2fRegistration;
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
     * migrate_u2f_to_webauthn, which Vaultwarden runs at startup: U2F keys
     * become WebAuthn keys marked as migrated, unless the user has WebAuthn
     * keys already.
     *
     * @throws ApiError
     */
    public static function migrateU2fToWebauthn(): void
    {
        foreach (self::query()->where('atype', self::TYPE_U2F)->get() as $u2f) {
            $registrations = U2fRegistration::parseList($u2f->data) ?? throw ApiError::message('Serde');
            if ($registrations === [] || $registrations[0]->migrated === true) {
                continue;
            }
            $existing = self::findByUserAndType($u2f->user_uuid, self::TYPE_WEBAUTHN);
            if ($existing !== null && (Registrations::parse($existing->data) ?? throw ApiError::message('Serde')) !== []) {
                continue;
            }

            $keys = [];
            foreach ($registrations as $registration) {
                $point = $registration->publicKey();
                if (strlen($point) < 65) {
                    throw ApiError::message('Invalid U2F public key');
                }
                $keys[] = ['id' => $registration->id, 'name' => $registration->name, 'migrated' => true,
                    'credential' => Credential::legacy($registration->keyHandle, CoseKey::es256(substr($point, 1, 32), substr($point, 33, 32)), $registration->counter, false, 'discouraged')];
            }

            $u2f->data = Json::encode(array_map(static fn (U2fRegistration $registration): array => $registration->toJson(true), $registrations));
            $u2f->save();
            self::new($u2f->user_uuid, self::TYPE_WEBAUTHN, Registrations::encode($keys))->save();
        }
    }

    /**
     * migrate_credential_to_passkey, which Vaultwarden runs at startup:
     * WebAuthn keys stored by webauthn-rs 0.3 get the current format.
     *
     * @throws ApiError
     */
    public static function migrateCredentialToPasskey(): void
    {
        foreach (self::query()->where('atype', self::TYPE_WEBAUTHN)->get() as $twoFactor) {
            // What does not parse as the old format was converted already.
            $registrations = Registrations::parseV3($twoFactor->data);
            if ($registrations !== null) {
                self::new($twoFactor->user_uuid, self::TYPE_WEBAUTHN, Registrations::encode($registrations))->save();
            }
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
