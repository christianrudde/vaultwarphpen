<?php

namespace App\Http\Data;

use App\Http\HttpStatus;

/**
 * UnlockData in src/api/core/accounts.rs: the user key, wrapped with the
 * master key the client derived.
 */
final readonly class UnlockData
{
    public function __construct(
        public string $salt,
        public KdfData $kdf,
        public string $masterKeyWrappedUserKey,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            $fields->string('salt'),
            KdfData::fromFields($fields->object('kdf')),
            $fields->string('masterKeyWrappedUserKey'),
        );
    }
}
