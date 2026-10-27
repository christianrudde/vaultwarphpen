<?php

namespace App\Http\Data;

use App\Http\HttpStatus;
use App\Models\User;

/**
 * KDFData in src/api/core/accounts.rs.
 */
final readonly class KdfData
{
    public function __construct(
        public int $kdf,
        public int $kdfIterations,
        public ?int $kdfMemory,
        public ?int $kdfParallelism,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        return new self(
            $fields->int('kdf', 'kdfType'),
            $fields->int('kdfIterations', 'iterations'),
            $fields->optionalInt('kdfMemory', 'memory'),
            $fields->optionalInt('kdfParallelism', 'parallelism'),
        );
    }

    public function equals(self $other): bool
    {
        return $this == $other;
    }

    public function matchesUser(User $user): bool
    {
        return $this->kdf === $user->client_kdf_type
            && $this->kdfIterations === $user->client_kdf_iter
            && $this->kdfMemory === $user->client_kdf_memory
            && $this->kdfParallelism === $user->client_kdf_parallelism;
    }
}
