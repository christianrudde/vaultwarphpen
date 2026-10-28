<?php

namespace App\Models;

use App\Database\Rows;
use App\Http\ApiError;
use Illuminate\Database\QueryException;

/**
 * Which folder of its user a cipher is in (FolderCipher in src/db/models/folder.rs).
 *
 * @property string $cipher_uuid
 * @property string $folder_uuid
 */
final class FolderCipher extends Model
{
    protected $table = 'folders_ciphers';

    protected $primaryKey = 'cipher_uuid';

    /**
     * Insert unless the pair exists.
     *
     * @throws ApiError
     */
    public static function add(string $folderUuid, string $cipherUuid): void
    {
        try {
            Rows::insertUnlessDuplicate('folders_ciphers', ['cipher_uuid' => $cipherUuid, 'folder_uuid' => $folderUuid], ['cipher_uuid', 'folder_uuid']);
        } catch (QueryException) {
            throw ApiError::message('Error adding cipher to folder');
        }
    }

    /**
     * @throws ApiError
     */
    public static function remove(string $folderUuid, string $cipherUuid): void
    {
        try {
            self::query()->where('cipher_uuid', $cipherUuid)->where('folder_uuid', $folderUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing cipher from folder');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCipher(string $cipherUuid): void
    {
        try {
            self::query()->where('cipher_uuid', $cipherUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing cipher from folders');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByFolder(string $folderUuid): void
    {
        try {
            self::query()->where('folder_uuid', $folderUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing ciphers from folder');
        }
    }

    public static function exists(string $folderUuid, string $cipherUuid): bool
    {
        return self::query()->where('folder_uuid', $folderUuid)->where('cipher_uuid', $cipherUuid)->exists();
    }

    /**
     * The folder of every cipher the user put in one, by cipher.
     *
     * @return array<string, string>
     */
    public static function folderByCipherForUser(string $userUuid): array
    {
        $folders = [];
        foreach (self::query()
            ->join('folders', 'folders.uuid', '=', 'folders_ciphers.folder_uuid')
            ->where('folders.user_uuid', $userUuid)
            ->get(['folders_ciphers.cipher_uuid', 'folders_ciphers.folder_uuid']) as $row) {
            $folders[$row->cipher_uuid] = $row->folder_uuid;
        }

        return $folders;
    }
}
