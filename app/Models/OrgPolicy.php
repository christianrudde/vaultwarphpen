<?php

namespace App\Models;

use App\Support\JsonValue;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use stdClass;

/**
 * An organization policy (src/db/models/org_policy.rs); so far the queries the login and Sends need.
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

    public const int TYPE_DISABLE_SEND = 6;

    public const int TYPE_SEND_OPTIONS = 7;

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

    /**
     * Whether a Send Options policy, in an organization where the user is a
     * confirmed member below admin, forbids hiding the email address.
     */
    public static function isHideEmailDisabled(string $userUuid): bool
    {
        foreach (self::findConfirmedByUserAndActivePolicy($userUuid, self::TYPE_SEND_OPTIONS) as $policy) {
            $member = Membership::query()->where('user_uuid', $userUuid)->where('org_uuid', $policy->org_uuid)
                ->where('status', Membership::STATUS_CONFIRMED)->first();
            if ($member === null || ! $member->typeBelow(Membership::TYPE_ADMIN)) {
                continue;
            }

            // SendOptionsPolicyData { disableHideEmail (alias DisableHideEmail): bool }
            [$ok, $data] = JsonValue::decode($policy->data);
            $values = $ok && $data instanceof stdClass ? array_values(array_intersect_key(get_object_vars($data), ['disableHideEmail' => true, 'DisableHideEmail' => true])) : [];
            if (count($values) !== 1 || ! is_bool($values[0])) {
                logger()->error("Failed to deserialize SendOptionsPolicyData: {$policy->data}");

                continue;
            }
            if ($values[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enabled policies of a type in organizations the user is confirmed in.
     *
     * @return Collection<int, self>
     */
    public static function findConfirmedByUserAndActivePolicy(string $userUuid, int $policyType): Collection
    {
        return self::query()
            ->select('org_policies.*')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'org_policies.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->where('org_policies.atype', $policyType)
            ->where('org_policies.enabled', true)
            ->get();
    }
}
