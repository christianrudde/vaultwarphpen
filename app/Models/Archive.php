<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use DateTimeImmutable;
use Illuminate\Database\QueryException;

/**
 * A cipher a user archived (src/db/models/archive.rs).
 *
 * @property string $user_uuid
 * @property string $cipher_uuid
 * @property DateTimeImmutable $archived_at
 */
final class Archive extends Model
{
    protected $table = 'archives';

    protected $primaryKey = 'cipher_uuid';

    protected function casts(): array
    {
        return ['archived_at' => NaiveDateTime::class];
    }

    public static function archivedAt(string $cipherUuid, string $userUuid): ?DateTimeImmutable
    {
        return self::query()->where('cipher_uuid', $cipherUuid)->where('user_uuid', $userUuid)->first()?->archived_at;
    }

    /**
     * @throws ApiError
     */
    public static function store(string $userUuid, string $cipherUuid, DateTimeImmutable $archivedAt): void
    {
        User::updateUuidRevision($userUuid);
        try {
            self::query()->upsert(
                [['user_uuid' => $userUuid, 'cipher_uuid' => $cipherUuid, 'archived_at' => NaiveDateTime::format($archivedAt)]],
                ['user_uuid', 'cipher_uuid'],
                ['archived_at'],
            );
        } catch (QueryException) {
            throw ApiError::message('Error saving archive');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteByCipher(string $userUuid, string $cipherUuid): void
    {
        User::updateUuidRevision($userUuid);
        try {
            self::query()->where('user_uuid', $userUuid)->where('cipher_uuid', $cipherUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting archive');
        }
    }

    /**
     * @return array<string, DateTimeImmutable> By cipher
     */
    public static function archivedAtByCipherForUser(string $userUuid): array
    {
        $archives = [];
        foreach (self::query()->where('user_uuid', $userUuid)->get() as $archive) {
            $archives[$archive->cipher_uuid] = $archive->archived_at;
        }

        return $archives;
    }
}
