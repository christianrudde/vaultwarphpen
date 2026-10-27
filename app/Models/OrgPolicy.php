<?php

namespace App\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;

/**
 * An organization policy (src/db/models/org_policy.rs); so far the queries the login needs.
 *
 * @property string $uuid
 * @property string $org_uuid
 * @property int $atype
 * @property bool $enabled
 * @property string $data
 * @property ?DateTimeImmutable $revision_date
 */
final class OrgPolicy extends Model
{
    public const int TYPE_TWO_FACTOR_AUTHENTICATION = 0;

    public const int TYPE_MASTER_PASSWORD = 1;

    public const int TYPE_PERSONAL_OWNERSHIP = 5;

    protected $table = 'org_policies';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'atype' => 'integer',
            'enabled' => 'boolean',
            'revision_date' => Casts\NaiveDateTime::class,
        ];
    }

    /**
     * Whether an enabled policy binds the user: a confirmed member below admin.
     */
    public static function isApplicableToUser(string $userUuid, int $policyType, ?string $excludeOrgUuid = null): bool
    {
        foreach (self::findAcceptedAndConfirmedByUserAndActivePolicy($userUuid, $policyType) as $policy) {
            if ($excludeOrgUuid !== null && $policy->org_uuid === $excludeOrgUuid) {
                continue;
            }

            $member = Membership::query()->where('user_uuid', $userUuid)->where('org_uuid', $policy->org_uuid)
                ->where('status', Membership::STATUS_CONFIRMED)->first();
            if ($member !== null && $member->typeBelow(Membership::TYPE_ADMIN)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enabled policies of a type in organizations the user accepted or is confirmed in.
     *
     * @return Collection<int, self>
     */
    public static function findAcceptedAndConfirmedByUserAndActivePolicy(string $userUuid, int $policyType): Collection
    {
        return self::query()
            ->select('org_policies.*')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'org_policies.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->whereIn('users_organizations.status', [Membership::STATUS_ACCEPTED, Membership::STATUS_CONFIRMED])
            ->where('org_policies.atype', $policyType)
            ->where('org_policies.enabled', true)
            ->get();
    }
}
