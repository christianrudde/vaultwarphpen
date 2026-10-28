<?php

namespace App\Models;

use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Support\Dates;
use App\Support\Random;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A cipher's file (src/db/models/attachment.rs), stored as
 * `<ATTACHMENTS_FOLDER>/<cipher_uuid>/<id>`.
 *
 * @property string $id
 * @property string $cipher_uuid
 * @property string $file_name
 * @property int $file_size
 * @property ?string $akey
 */
final class Attachment extends Model
{
    protected $table = 'attachments';

    protected $primaryKey = 'id';

    protected function casts(): array
    {
        return ['file_size' => 'integer'];
    }

    public static function new(string $id, string $cipherUuid, string $fileName, int $fileSize, ?string $key): self
    {
        $attachment = new self;
        $attachment->setRawAttributes([
            'id' => $id,
            'cipher_uuid' => $cipherUuid,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'akey' => $key,
        ]);

        return $attachment;
    }

    /**
     * crypto::generate_attachment_id: 80 random bits, scoped to the cipher.
     */
    public static function generateId(): string
    {
        return Random::hex(10);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->upsertRow(['id'], 'Error saving attachment');

        return true;
    }

    public function filePath(): string
    {
        return self::path($this->cipher_uuid, $this->id);
    }

    /**
     * Where the file of an attachment lies.
     */
    public static function path(string $cipherUuid, string $id): string
    {
        return app(Config::class)->path('attachments_folder')."/{$cipherUuid}/{$id}";
    }

    /**
     * Attachment::to_json: the download URL carries a one-minute token.
     *
     * @return array<string, mixed>
     */
    public function toApi(string $host): array
    {
        return Json::sorted([
            'id' => $this->id,
            'url' => "{$host}/attachments/{$this->cipher_uuid}/{$this->id}?token={$this->downloadToken()}",
            'fileName' => $this->file_name,
            'size' => (string) $this->file_size,
            'sizeName' => self::displaySize($this->file_size),
            'key' => $this->akey,
            'object' => 'attachment',
        ]);
    }

    /**
     * Remove the row (with up to ten attempts) and the file.
     *
     * @throws ApiError
     */
    public function deleteAttachment(): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                self::query()->where('id', $this->id)->delete();

                break;
            } catch (QueryException) {
                if ($attempt >= 10) {
                    throw ApiError::message('Error deleting attachment');
                }
                usleep(500_000);
            }
        }

        $file = $this->filePath();
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Ids are stored in lower case.
     */
    public static function findById(string $id): ?self
    {
        return self::query()->where('id', strtolower($id))->first();
    }

    /**
     * The bytes of all attachments of the user's own ciphers; i64::MAX when
     * the sum does not fit.
     */
    public static function sizeByUser(string $userUuid): int
    {
        return self::sumOfSizes(self::query()->join('ciphers', 'ciphers.uuid', '=', 'attachments.cipher_uuid')->where('ciphers.user_uuid', $userUuid)->sum('attachments.file_size'));
    }

    public static function sizeByOrg(string $orgUuid): int
    {
        return self::sumOfSizes(self::query()->join('ciphers', 'ciphers.uuid', '=', 'attachments.cipher_uuid')->where('ciphers.organization_uuid', $orgUuid)->sum('attachments.file_size'));
    }

    public static function countByUser(string $userUuid): int
    {
        return self::query()->join('ciphers', 'ciphers.uuid', '=', 'attachments.cipher_uuid')->where('ciphers.user_uuid', $userUuid)->count();
    }

    public static function countByOrg(string $orgUuid): int
    {
        return self::query()->join('ciphers', 'ciphers.uuid', '=', 'attachments.cipher_uuid')->where('ciphers.organization_uuid', $orgUuid)->count();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByCipher(string $cipherUuid): Collection
    {
        return self::query()->where('cipher_uuid', $cipherUuid)->get();
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCipher(string $cipherUuid): void
    {
        foreach (self::findByCipher($cipherUuid) as $attachment) {
            $attachment->deleteAttachment();
        }
    }

    /**
     * util::get_display_size: two decimals of the largest unit below 1024.
     */
    public static function displaySize(int $size): string
    {
        $units = ['bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) $size;
        $unit = 0;
        while ($value > 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.2f %s', $value, $units[$unit]);
    }

    private static function sumOfSizes(mixed $sum): int
    {
        if (is_int($sum)) {
            return $sum;
        }
        if (! is_numeric($sum)) {
            return 0;
        }

        return (float) $sum >= (float) PHP_INT_MAX ? PHP_INT_MAX : (int) $sum;
    }

    /**
     * FileDownloadClaims
     */
    private function downloadToken(): string
    {
        $jwt = app(Jwt::class);
        $now = Dates::now()->getTimestamp();

        return $jwt->encode([
            'nbf' => $now,
            'exp' => $now + 60,
            'iss' => $jwt->issuer(JwtIssuer::FileDownload),
            'sub' => $this->cipher_uuid,
            'file_id' => $this->id,
        ]);
    }
}
