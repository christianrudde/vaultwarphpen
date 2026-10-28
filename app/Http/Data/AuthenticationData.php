<?php

namespace App\Http\Data;

use App\Http\ApiError;
use App\Http\HttpStatus;
use App\Models\User;

/**
 * AuthenticationData in src/api/core/accounts.rs: how the client derives
 * the master password hash it sends.
 */
final readonly class AuthenticationData
{
    public function __construct(
        public string $salt,
        public KdfData $kdf,
        public string $masterPasswordAuthenticationHash,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            $fields->string('salt', 'Salt'),
            KdfData::fromFields($fields->object('kdf', 'Kdf')),
            $fields->string('masterPasswordAuthenticationHash', 'MasterPasswordAuthenticationHash'),
        );
    }

    /**
     * Both must use the KDF settings and the salt of the user.
     *
     * @throws ApiError
     */
    public function check(User $user, UnlockData $unlock): void
    {
        if (! $this->kdf->equals($unlock->kdf)) {
            throw ApiError::message('KDF settings must be equal for authentication and unlock');
        }
        if ($this->salt !== $user->masterPasswordSalt() || $this->salt !== $unlock->salt) {
            throw ApiError::message('Invalid master password salt');
        }
    }
}
