<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

/**
 * src/db/models/emergency_access.rs; so far the parts registration and the
 * scheduled jobs need.
 *
 * @property string $uuid
 * @property string $grantor_uuid
 * @property ?string $grantee_uuid
 * @property ?string $email
 * @property ?string $key_encrypted
 * @property int $atype
 * @property int $status
 * @property int $wait_time_days
 * @property ?DateTimeImmutable $recovery_initiated_at
 * @property ?DateTimeImmutable $last_notification_at
 * @property DateTimeImmutable $updated_at
 * @property DateTimeImmutable $created_at
 */
final class EmergencyAccess extends Model
{
    public const int STATUS_INVITED = 0;

    public const int STATUS_ACCEPTED = 1;

    public const int STATUS_CONFIRMED = 2;

    public const int STATUS_RECOVERY_INITIATED = 3;

    public const int STATUS_RECOVERY_APPROVED = 4;

    public const int TYPE_VIEW = 0;

    public const int TYPE_TAKEOVER = 1;

    /**
     * EMERGENCY_ACCESS_TIMEOUT_RANGE
     */
    public const array WAIT_TIME_DAYS = [1, 90];

    protected $table = 'emergency_access';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'status' => 'integer',
            'wait_time_days' => 'integer',
            'recovery_initiated_at' => NaiveDateTime::class,
            'last_notification_at' => NaiveDateTime::class,
            'updated_at' => NaiveDateTime::class,
            'created_at' => NaiveDateTime::class,
        ];
    }

    public function typeAsString(): string
    {
        return $this->atype === self::TYPE_VIEW ? 'View' : 'Takeover';
    }

    /**
     * When the grantee gets access without the grantor's approval.
     *
     * @throws ApiError
     */
    public function deadlineAt(): DateTimeImmutable
    {
        if ($this->wait_time_days < self::WAIT_TIME_DAYS[0] || $this->wait_time_days > self::WAIT_TIME_DAYS[1]) {
            throw ApiError::message('Emergency access wait time is out-of-bounds!');
        }
        if ($this->recovery_initiated_at === null) {
            throw ApiError::message('Emergency access initiation date missing!');
        }

        return $this->recovery_initiated_at->modify("+{$this->wait_time_days} days");
    }

    /**
     * @throws ApiError
     */
    public function isRecoveryAllowed(DateTimeImmutable $now): bool
    {
        return $this->deadlineAt() <= $now;
    }

    /**
     * Saving also tells the grantor's clients to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        User::updateUuidRevision($this->grantor_uuid);
        $this->updated_at = Dates::now();
        $this->upsertRow(['uuid'], 'Error saving emergency access');

        return true;
    }

    /**
     * Changes only the status, and only if no one else has changed it, so
     * that the scheduled jobs do not overwrite each other. The grantee's
     * clients sync.
     *
     * @throws ApiError
     */
    public function updateAccessStatusAndSave(int $fromStatus, int $toStatus, DateTimeImmutable $date): void
    {
        $updated = self::retry(
            fn (): int => self::query()->where('uuid', $this->uuid)->where('status', $fromStatus)
                ->update(['status' => $toStatus, 'updated_at' => NaiveDateTime::format($date)]),
            'Error updating emergency access status',
        );
        if ($updated !== 1) {
            throw ApiError::message('Emergency access status was changed already');
        }

        $this->status = $toStatus;
        $this->updated_at = $date;

        User::updateUuidRevision((string) $this->grantee_uuid);
    }

    /**
     * Changes only the date of the last reminder.
     *
     * @throws ApiError
     */
    public function updateLastNotificationDateAndSave(DateTimeImmutable $date): void
    {
        $this->last_notification_at = $date;
        $this->updated_at = $date;

        self::retry(
            fn (): int => self::query()->where('uuid', $this->uuid)
                ->update(['last_notification_at' => NaiveDateTime::format($date), 'updated_at' => NaiveDateTime::format($date)]),
            'Error updating emergency access status',
        );
    }

    /**
     * Tells the grantor's clients to sync.
     *
     * @throws ApiError
     */
    public function deleteAccess(): void
    {
        User::updateUuidRevision($this->grantor_uuid);
        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing user from emergency access');
        }
    }

    /**
     * The accesses the user grants and those granted to the user.
     *
     * @throws ApiError
     */
    public static function deleteAllByUser(string $userUuid): void
    {
        foreach (self::query()->where('grantor_uuid', $userUuid)->get() as $access) {
            $access->deleteAccess();
        }
        foreach (self::query()->where('grantee_uuid', $userUuid)->get() as $access) {
            $access->deleteAccess();
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByGranteeEmail(string $email): void
    {
        foreach (self::findAllInvitedByGranteeEmail($email) as $access) {
            $access->deleteAccess();
        }
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAllRecoveriesInitiated(): Collection
    {
        return self::query()->where('status', self::STATUS_RECOVERY_INITIATED)->whereNotNull('recovery_initiated_at')->get();
    }

    public static function findInvitedByGranteeEmail(string $email): ?self
    {
        return self::query()->where('email', $email)->where('status', self::STATUS_INVITED)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAllInvitedByGranteeEmail(string $email): Collection
    {
        return self::query()->where('email', $email)->where('status', self::STATUS_INVITED)->get();
    }

    /**
     * @throws ApiError
     */
    public function acceptInvite(string $granteeUuid, string $granteeEmail): void
    {
        if ($this->email === null || $this->email !== $granteeEmail) {
            throw ApiError::message('User email does not match invite.');
        }
        if ($this->status === self::STATUS_ACCEPTED) {
            throw ApiError::message('Emergency contact already accepted.');
        }

        $this->status = self::STATUS_ACCEPTED;
        $this->grantee_uuid = $granteeUuid;
        $this->email = null;
        $this->save();
    }
}
