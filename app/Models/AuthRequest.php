<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A login with a device (src/db/models/auth_request.rs); so far the parts the
 * scheduled job needs.
 *
 * @property string $uuid
 * @property string $user_uuid
 * @property ?string $organization_uuid
 * @property string $request_device_identifier
 * @property int $device_type
 * @property string $request_ip
 * @property ?string $response_device_id
 * @property string $access_code
 * @property string $public_key
 * @property ?string $enc_key
 * @property ?string $master_password_hash
 * @property ?bool $approved
 * @property DateTimeImmutable $creation_date
 * @property ?DateTimeImmutable $response_date
 * @property ?DateTimeImmutable $authentication_date
 */
final class AuthRequest extends Model
{
    protected $table = 'auth_requests';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'device_type' => 'integer',
            'approved' => 'boolean',
            'creation_date' => NaiveDateTime::class,
            'response_date' => NaiveDateTime::class,
            'authentication_date' => NaiveDateTime::class,
        ];
    }

    /**
     * @throws ApiError
     */
    public function deleteRequest(): void
    {
        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting auth request');
        }
    }

    /**
     * @return Collection<int, self>
     */
    public static function findCreatedBefore(DateTimeImmutable $date): Collection
    {
        return self::query()->where('creation_date', '<', NaiveDateTime::format($date))->get();
    }

    /**
     * Deletes the requests older than 15 minutes, like Bitwarden's
     * AuthRequest_DeleteIfExpired; failures are ignored like upstream.
     */
    public static function purgeExpiredAuthRequests(): void
    {
        foreach (self::findCreatedBefore(Dates::now()->modify('-15 minutes')) as $request) {
            try {
                $request->deleteRequest();
            } catch (ApiError) {
                // Tried again on the next run.
            }
        }
    }
}
