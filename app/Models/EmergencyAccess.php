<?php

namespace App\Models;

use App\Http\ApiError;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * src/db/models/emergency_access.rs; so far the parts registration needs.
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
