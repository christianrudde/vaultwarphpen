<?php

namespace App\Http\Data;

use App\Http\HttpStatus;

/**
 * RegisterData in src/api/core/accounts.rs. The password data comes in two
 * shapes (the untagged RegisterDataCompat): the old flat one, tried first,
 * and the current one with masterPasswordAuthentication and masterPasswordUnlock.
 */
final readonly class RegisterData
{
    /**
     * @param  array{publicKey: string, encryptedPrivateKey: string}|null  $keys
     * @param  array{authenticationKdf: KdfData, authenticationSalt: string, unlockKdf: KdfData, unlockSalt: string}|null  $current
     */
    public function __construct(
        public string $email,
        public KdfData $kdf,
        public string $hash,
        public string $key,
        public ?array $current,
        public ?array $keys,
        public ?string $masterPasswordHint,
        public ?string $organizationUserId,
        public ?string $emailVerificationToken,
        public ?string $acceptEmergencyAccessId,
        public ?string $acceptEmergencyAccessInviteToken,
        public ?string $orgInviteToken,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        $email = $fields->string('email');

        try {
            // RegisterDataOld, flattened into the object.
            $kdf = KdfData::fromFields($fields);
            $key = $fields->string('key', 'userSymmetricKey');
            $hash = $fields->string('master_password_hash', 'masterPasswordHash');
            $current = null;
        } catch (HttpStatus) {
            try {
                // RegisterDataCur
                $authentication = $fields->object('masterPasswordAuthentication', 'MasterPasswordAuthentication');
                $unlock = $fields->object('masterPasswordUnlock', 'MasterPasswordUnlock');
                $kdf = KdfData::fromFields($authentication->object('kdf', 'Kdf'));
                $hash = $authentication->string('hash', 'masterPasswordAuthenticationHash', 'MasterPasswordAuthenticationHash');
                $key = $unlock->string('key', 'masterKeyWrappedUserKey');
                $current = [
                    'authenticationKdf' => $kdf,
                    'authenticationSalt' => $authentication->string('salt', 'Salt'),
                    'unlockKdf' => KdfData::fromFields($unlock->object('kdf')),
                    'unlockSalt' => $unlock->string('salt'),
                ];
            } catch (HttpStatus) {
                throw new HttpStatus(422, 'data did not match any variant of untagged enum RegisterDataCompat');
            }
        }

        $keysFields = $fields->optionalObject('keys', 'userAsymmetricKeys');

        return new self(
            email: $email,
            kdf: $kdf,
            hash: $hash,
            key: $key,
            current: $current,
            keys: $keysFields === null ? null : [
                'encryptedPrivateKey' => $keysFields->string('encryptedPrivateKey'),
                'publicKey' => $keysFields->string('publicKey'),
            ],
            masterPasswordHint: $fields->optionalString('masterPasswordHint'),
            organizationUserId: $fields->optionalString('organizationUserId'),
            emailVerificationToken: $fields->optionalString('emailVerificationToken'),
            acceptEmergencyAccessId: $fields->optionalString('acceptEmergencyAccessId'),
            acceptEmergencyAccessInviteToken: $fields->optionalString('acceptEmergencyAccessInviteToken'),
            orgInviteToken: $fields->optionalString('orgInviteToken', 'token'),
        );
    }

    /**
     * The current shape must agree with itself: the same KDF twice, and the
     * normalized email as both salts.
     */
    public function unprocessable(): bool
    {
        if ($this->current === null) {
            return false;
        }

        $email = mb_strtolower(trim($this->email));

        return ! $this->current['authenticationKdf']->equals($this->current['unlockKdf'])
            || $this->current['authenticationSalt'] !== $email
            || $this->current['unlockSalt'] !== $email;
    }
}
