<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * A login waiting for its second factor (src/db/models/two_factor_incomplete.rs).
 * With mail, users hear of logins that are not completed in time. The device
 * id is what the client claims.
 *
 * @property string $user_uuid
 * @property string $device_uuid
 * @property string $device_name
 * @property int $device_type
 * @property DateTimeImmutable $login_time
 * @property string $ip_address
 */
final class TwoFactorIncomplete extends Model
{
    protected $table = 'twofactor_incomplete';

    protected function casts(): array
    {
        return [
            'device_type' => 'integer',
            'login_time' => NaiveDateTime::class,
        ];
    }

    /**
     * Only the first attempt counts, so that retrying does not delay the mail.
     *
     * @throws ApiError
     */
    public static function markIncomplete(string $userUuid, string $deviceUuid, string $deviceName, int $deviceType, string $ip): void
    {
        if (! self::tracked() || self::findByUserAndDevice($userUuid, $deviceUuid) !== null) {
            return;
        }
        try {
            self::query()->insert([
                'user_uuid' => $userUuid,
                'device_uuid' => $deviceUuid,
                'device_name' => $deviceName,
                'device_type' => $deviceType,
                'login_time' => NaiveDateTime::format(Dates::now()),
                'ip_address' => $ip,
            ]);
        } catch (QueryException) {
            throw ApiError::message('Error adding twofactor_incomplete record');
        }
    }

    /**
     * @throws ApiError
     */
    public static function markComplete(string $userUuid, string $deviceUuid): void
    {
        if (self::tracked()) {
            self::deleteByUserAndDevice($userUuid, $deviceUuid);
        }
    }

    public static function findByUserAndDevice(string $userUuid, string $deviceUuid): ?self
    {
        return self::query()->where('user_uuid', $userUuid)->where('device_uuid', $deviceUuid)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findLoginsBefore(DateTimeImmutable $date): Collection
    {
        return self::query()->where('login_time', '<', NaiveDateTime::format($date))->get();
    }

    /**
     * The user of the latest pending login of the device.
     */
    public static function userOfDevice(string $deviceUuid): ?string
    {
        $user = self::query()->where('device_uuid', $deviceUuid)->orderByDesc('login_time')->value('user_uuid');

        return is_string($user) ? $user : null;
    }

    /**
     * @throws ApiError
     */
    public static function deleteByUserAndDevice(string $userUuid, string $deviceUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->where('device_uuid', $deviceUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error in twofactor_incomplete::delete_by_user_and_device()');
        }
    }

    private static function tracked(): bool
    {
        $config = app(Config::class);

        return $config->int('incomplete_2fa_time_limit') !== 0 && $config->mailEnabled();
    }
}
