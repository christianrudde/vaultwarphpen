<?php

namespace App\Models;

use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A cipher's file (src/db/models/attachment.rs); so far listing and deleting.
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

    /**
     * Attachment::to_json: the download URL carries a one-minute token.
     *
     * @return array<string, mixed>
     */
    public function toApi(string $host): array
    {
        /** @var array<string, mixed> */
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

        $file = app(Config::class)->path('attachments_folder')."/{$this->cipher_uuid}/{$this->id}";
        if (is_file($file)) {
            unlink($file);
        }
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
