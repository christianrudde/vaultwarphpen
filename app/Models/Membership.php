<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;

/**
 * A user's membership in an organization (Membership in
 * src/db/models/organization.rs); so far the parts accounts and login need.
 *
 * @property string $uuid
 * @property string $user_uuid
 * @property string $org_uuid
 * @property bool $access_all
 * @property ?string $akey
 * @property int $status
 * @property int $atype
 * @property ?string $reset_password_key
 * @property ?string $external_id
 * @property ?string $invited_by_email
 */
final class Membership extends Model
{
    public const int STATUS_REVOKED = -1;

    public const int STATUS_INVITED = 0;

    public const int STATUS_ACCEPTED = 1;

    public const int STATUS_CONFIRMED = 2;

    public const int TYPE_OWNER = 0;

    public const int TYPE_ADMIN = 1;

    public const int TYPE_USER = 2;

    public const int TYPE_MANAGER = 3;

    /**
     * Revoking lowers the status by this much, restoring raises it again.
     */
    private const int ACTIVATE_REVOKE_DIFF = 128;

    /**
     * MembershipType's ordering by access: user < manager < admin < owner.
     */
    private const array ACCESS_LEVEL = [self::TYPE_OWNER => 3, self::TYPE_ADMIN => 2, self::TYPE_USER => 0, self::TYPE_MANAGER => 1];

    protected $table = 'users_organizations';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'atype' => 'integer',
            'access_all' => 'boolean',
        ];
    }

    /**
     * Whether the type ranks below another one; unknown types are not comparable.
     */
    public function typeBelow(int $type): bool
    {
        $own = self::ACCESS_LEVEL[$this->atype] ?? null;

        return $own !== null && $own < self::ACCESS_LEVEL[$type];
    }

    public function revoke(): bool
    {
        if ($this->status > self::STATUS_REVOKED) {
            $this->status -= self::ACTIVATE_REVOKE_DIFF;

            return true;
        }

        return false;
    }

    /**
     * Saving also tells the member's clients to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        User::updateUuidRevision($this->user_uuid);
        $this->upsertRow(['uuid'], 'Error adding user to organization');

        return true;
    }

    /**
     * @throws ApiError
     */
    public static function acceptUserInvitations(string $userUuid): void
    {
        try {
            self::query()->where('user_uuid', $userUuid)->where('status', self::STATUS_INVITED)
                ->update(['status' => self::STATUS_ACCEPTED]);
        } catch (QueryException) {
            throw ApiError::message('Error confirming invitations');
        }
    }

    /**
     * Confirmed memberships in organizations where a policy is enabled.
     *
     * @return Collection<int, self>
     */
    public static function findByUserAndPolicy(string $userUuid, int $policyType): Collection
    {
        try {
            return self::query()
                ->select('users_organizations.*')
                ->join('org_policies', function (JoinClause $join) use ($userUuid, $policyType): void {
                    $join->on('org_policies.org_uuid', '=', 'users_organizations.org_uuid')
                        ->where('users_organizations.user_uuid', '=', $userUuid)
                        ->where('org_policies.atype', '=', $policyType)
                        ->where('org_policies.enabled', '=', true);
                })
                ->where('users_organizations.status', self::STATUS_CONFIRMED)
                ->get();
        } catch (QueryException) {
            return new Collection;
        }
    }
}
