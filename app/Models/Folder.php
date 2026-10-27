<?php

namespace App\Models;

use App\Http\ApiError;
use App\Http\Json;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\Random;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * src/db/models/folder.rs
 *
 * @property string $uuid
 * @property DateTimeImmutable $created_at
 * @property DateTimeImmutable $updated_at
 * @property string $user_uuid
 * @property string $name
 */
final class Folder extends Model
{
    protected $table = 'folders';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'created_at' => NaiveDateTime::class,
            'updated_at' => NaiveDateTime::class,
        ];
    }

    public static function new(string $userUuid, string $name): self
    {
        $now = Dates::now();

        return new self([
            'uuid' => Random::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
            'user_uuid' => $userUuid,
            'name' => $name,
        ]);
    }

    /**
     * Folder::to_json
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        /** @var array<string, mixed> */
        return Json::sorted([
            'id' => $this->uuid,
            'revisionDate' => Dates::format($this->updated_at),
            'name' => $this->name,
            'object' => 'folder',
        ]);
    }

    /**
     * Saving tells the owner's clients to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        User::updateUuidRevision($this->user_uuid);
        $this->updated_at = Dates::now();
        $this->upsertRow(['uuid'], 'Error saving folder');

        return true;
    }

    /**
     * The folder goes, its ciphers stay.
     *
     * @throws ApiError
     */
    public function deleteFolder(): void
    {
        User::updateUuidRevision($this->user_uuid);
        FolderCipher::deleteAllByFolder($this->uuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting folder');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByUser(string $userUuid): void
    {
        foreach (self::findByUser($userUuid) as $folder) {
            $folder->deleteFolder();
        }
    }

    public static function findByUuidAndUser(string $uuid, string $userUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('user_uuid', $userUuid)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->get();
    }
}
