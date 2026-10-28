<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\Random;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A login with a device (src/db/models/auth_request.rs): a new device asks,
 * one of the user's devices approves with the encrypted key, and the new
 * device logs in once with the access code within five minutes.
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

    public static function new(string $userUuid, string $deviceIdentifier, int $deviceType, string $ip, string $accessCode, string $publicKey): self
    {
        return new self([
            'uuid' => Random::uuid(),
            'user_uuid' => $userUuid,
            'organization_uuid' => null,
            'request_device_identifier' => $deviceIdentifier,
            'device_type' => $deviceType,
            'request_ip' => $ip,
            'response_device_id' => null,
            'access_code' => $accessCode,
            'public_key' => $publicKey,
            'enc_key' => null,
            'master_password_hash' => null,
            'approved' => null,
            'creation_date' => Dates::now(),
            'response_date' => null,
            'authentication_date' => null,
        ]);
    }

    /**
     * The request as the clients see it, with the given response date.
     *
     * @return array<string, mixed>
     */
    public function toApi(?DateTimeImmutable $responseDate = null): array
    {
        $responseDate ??= $this->response_date;

        return Json::sorted([
            'id' => $this->uuid,
            'publicKey' => $this->public_key,
            'requestDeviceType' => DeviceType::fromInt($this->device_type)->displayName(),
            'requestIpAddress' => $this->request_ip,
            'key' => $this->enc_key,
            'masterPasswordHash' => $this->master_password_hash,
            'creationDate' => Dates::format($this->creation_date),
            'responseDate' => $responseDate === null ? null : Dates::format($responseDate),
            'requestApproved' => $this->approved,
            'origin' => app(Config::class)->string('domain_origin'),
            'object' => 'auth-request',
        ]);
    }

    /**
     * AuthRequest::to_json_for_pending_device
     *
     * @return array<string, mixed>
     */
    public function toApiForPendingDevice(): array
    {
        return Json::sorted(['id' => $this->uuid, 'creationDate' => Dates::format($this->creation_date)]);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->upsertRow(['uuid'], 'Error saving auth_request');

        return true;
    }

    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
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

    /**
     * The latest request of the device that waits for an answer.
     */
    public static function findByUserAndRequestedDevice(string $userUuid, string $deviceUuid): ?self
    {
        return self::query()->where('user_uuid', $userUuid)->where('request_device_identifier', $deviceUuid)
            ->whereNull('approved')->orderByDesc('creation_date')->first();
    }

    /**
     * Marks the request as used to log in; false when it already was, so it works once.
     *
     * @throws ApiError
     */
    public static function setAuthenticationDate(string $uuid): bool
    {
        try {
            return self::query()->where('uuid', $uuid)->whereNull('authentication_date')
                ->update(['authentication_date' => NaiveDateTime::format(Dates::now())]) === 1;
        } catch (QueryException) {
            throw ApiError::message('Error updating auth request');
        }
    }

    public function checkAccessCode(string $accessCode): bool
    {
        return hash_equals($this->access_code, $accessCode);
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
