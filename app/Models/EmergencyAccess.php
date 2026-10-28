<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\Random;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * src/db/models/emergency_access.rs: a grantor's trust in a grantee, who may
 * view or take over the grantor's vault after a wait time.
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
    public const int TYPE_VIEW = 0;

    public const int TYPE_TAKEOVER = 1;

    public const int STATUS_INVITED = 0;

    public const int STATUS_ACCEPTED = 1;

    public const int STATUS_CONFIRMED = 2;

    public const int STATUS_RECOVERY_INITIATED = 3;

    public const int STATUS_RECOVERY_APPROVED = 4;

    /**
     * EMERGENCY_ACCESS_TIMEOUT_RANGE: the wait time in days.
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

    public static function new(string $grantorUuid, string $email, int $status, int $atype, int $waitTimeDays): self
    {
        $now = Dates::now();

        return new self([
            'uuid' => Random::uuid(),
            'grantor_uuid' => $grantorUuid,
            'grantee_uuid' => null,
            'email' => $email,
            'key_encrypted' => null,
            'atype' => $atype,
            'status' => $status,
            'wait_time_days' => $waitTimeDays,
            'recovery_initiated_at' => null,
            'last_notification_at' => null,
            'updated_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * EmergencyAccessType::from_str: the number or the name.
     */
    public static function typeFromString(string $type): ?int
    {
        return match ($type) {
            '0', 'View' => self::TYPE_VIEW,
            '1', 'Takeover' => self::TYPE_TAKEOVER,
            default => null,
        };
    }

    public static function isValidWaitTime(int $days): bool
    {
        return $days >= self::WAIT_TIME_DAYS[0] && $days <= self::WAIT_TIME_DAYS[1];
    }

    public function typeAsString(): string
    {
        return $this->atype === self::TYPE_VIEW ? 'View' : 'Takeover';
    }

    /**
     * EmergencyAccess::to_json
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->uuid,
            'object' => 'emergencyAccess',
            'status' => $this->status,
            'type' => $this->atype,
            'waitTimeDays' => $this->wait_time_days,
        ];
    }

    /**
     * EmergencyAccess::to_json_grantor_details: upstream panics without the grantor.
     *
     * @return array<string, mixed>
     */
    public function toApiGrantorDetails(): array
    {
        $grantor = User::findByUuid($this->grantor_uuid) ?? throw new LogicException('Grantor user not found.');

        return [
            'avatarColor' => $grantor->avatar_color,
            'email' => $grantor->email,
            'grantorId' => $grantor->uuid,
            'id' => $this->uuid,
            'name' => $grantor->name,
            'object' => 'emergencyAccessGrantorDetails',
            'status' => $this->status,
            'type' => $this->atype,
            'waitTimeDays' => $this->wait_time_days,
        ];
    }

    /**
     * EmergencyAccess::to_json_grantee_details: an invited grantee is found by
     * email. Invitations to emails without a user are removed and give none;
     * a missing accepted grantee makes upstream panic.
     *
     * @return array<string, mixed>|null
     */
    public function toApiGranteeDetails(): ?array
    {
        if ($this->grantee_uuid !== null) {
            $grantee = User::findByUuid($this->grantee_uuid) ?? throw new LogicException('Grantee user not found.');
        } else {
            if ($this->email === null) {
                return null;
            }
            $grantee = User::findByMail($this->email);
            if ($grantee === null) {
                // Like `.ok()`: failures are ignored.
                try {
                    self::deleteAllByGranteeEmail($this->email);
                } catch (ApiError) {
                }

                return null;
            }
        }

        return [
            'avatarColor' => $grantee->avatar_color,
            'email' => $grantee->email,
            'granteeId' => $grantee->uuid,
            'id' => $this->uuid,
            'name' => $grantee->name,
            'object' => 'emergencyAccessGranteeDetails',
            'status' => $this->status,
            'type' => $this->atype,
            'waitTimeDays' => $this->wait_time_days,
        ];
    }

    /**
     * When the grantee gets access without the grantor's approval.
     *
     * @throws ApiError
     */
    public function deadlineAt(): DateTimeImmutable
    {
        if (! self::isValidWaitTime($this->wait_time_days)) {
            throw ApiError::message('Emergency access wait time is out-of-bounds!');
        }
        $initiatedAt = $this->recovery_initiated_at ?? throw ApiError::message('Emergency access initiation date missing!');

        return $initiatedAt->add(new DateInterval("P{$this->wait_time_days}D"));
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
        foreach (self::findAllByGrantorUuid($userUuid) as $access) {
            $access->deleteAccess();
        }
        foreach (self::findAllByGranteeUuid($userUuid) as $access) {
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

    public static function findByGrantorUuidAndGranteeUuidOrEmail(string $grantorUuid, string $granteeUuid, string $email): ?self
    {
        return self::query()->where('grantor_uuid', $grantorUuid)
            ->where(static fn ($query) => $query->where('grantee_uuid', $granteeUuid)->orWhere('email', $email))
            ->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAllRecoveriesInitiated(): Collection
    {
        return self::query()->where('status', self::STATUS_RECOVERY_INITIATED)->whereNotNull('recovery_initiated_at')->get();
    }

    public static function findByUuidAndGrantorUuid(string $uuid, string $grantorUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('grantor_uuid', $grantorUuid)->first();
    }

    public static function findByUuidAndGranteeUuid(string $uuid, string $granteeUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('grantee_uuid', $granteeUuid)->first();
    }

    public static function findByUuidAndGranteeEmail(string $uuid, string $email): ?self
    {
        return self::query()->where('uuid', $uuid)->where('email', $email)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAllByGranteeUuid(string $granteeUuid): Collection
    {
        return self::query()->where('grantee_uuid', $granteeUuid)->get();
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
     * @return Collection<int, self>
     */
    public static function findAllByGrantorUuid(string $grantorUuid): Collection
    {
        return self::query()->where('grantor_uuid', $grantorUuid)->get();
    }

    /**
     * Confirmed or further along.
     *
     * @return Collection<int, self>
     */
    public static function findAllConfirmedByGrantorUuid(string $grantorUuid): Collection
    {
        return self::query()->where('grantor_uuid', $grantorUuid)->where('status', '>=', self::STATUS_CONFIRMED)->get();
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
