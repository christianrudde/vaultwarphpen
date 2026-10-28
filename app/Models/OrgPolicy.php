<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Support\Dates;
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

    public const int TYPE_SINGLE_ORG = 3;

    public const int TYPE_PERSONAL_OWNERSHIP = 5;

    public const int TYPE_DISABLE_SEND = 6;

    public const int TYPE_SEND_OPTIONS = 7;

    public const int TYPE_RESET_PASSWORD = 8;

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

    /**
     * OrgPolicy::to_json
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        [$ok, $data] = JsonValue::decode($this->data);
        $policy = [
            'id' => $this->uuid,
            'organizationId' => $this->org_uuid,
            'type' => $this->atype,
            'data' => $ok ? JsonValue::toArrays($data) : null,
            'enabled' => $this->enabled,
            'revisionDate' => $this->revision_date === null ? null : Dates::format($this->revision_date),
            'object' => 'policy',
        ];
        // Like upstream, the reset password policy may always be toggled.
        if ($this->atype === self::TYPE_RESET_PASSWORD) {
            $policy['canToggleState'] = true;
        }

        return Json::sorted($policy);
    }

    public static function findByOrgAndType(string $orgUuid, int $policyType): ?self
    {
        return self::query()->where('org_uuid', $orgUuid)->where('atype', $policyType)->first();
    }

    /**
     * Policies of organizations the user is confirmed in, enabled or not.
     *
     * @return Collection<int, self>
     */
    public static function findConfirmedByUser(string $userUuid): Collection
    {
        return self::ofMemberships($userUuid, [Membership::STATUS_CONFIRMED]);
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAcceptedAndConfirmedByUser(string $userUuid): Collection
    {
        return self::ofMemberships($userUuid, [Membership::STATUS_ACCEPTED, Membership::STATUS_CONFIRMED]);
    }

    /**
     * OrgPolicy::check_user_allowed: users and managers who take part may
     * have to use two-factor login, and a single organization policy keeps
     * them out of other organizations.
     *
     * @throws ApiError
     */
    public static function checkUserAllowed(Membership $member, string $action): void
    {
        if (! $member->typeBelow(Membership::TYPE_ADMIN) || $member->status <= Membership::STATUS_INVITED) {
            return;
        }

        $twoFactor = self::findByOrgAndType($member->org_uuid, self::TYPE_TWO_FACTOR_AUTHENTICATION);
        if ($twoFactor !== null && $twoFactor->enabled && TwoFactor::findByUser($member->user_uuid)->isEmpty()) {
            if (app(Config::class)->bool('email_2fa_auto_fallback')) {
                throw ApiError::message('Email two-factor authentication is not implemented yet');
            }
            throw ApiError::message("Cannot {$action} because 2FA is required (membership {$member->uuid})");
        }

        if (self::isApplicableToUser($member->user_uuid, self::TYPE_SINGLE_ORG, $member->org_uuid)) {
            throw ApiError::message("Cannot {$action} because another organization policy forbids it (membership {$member->uuid})");
        }

        $singleOrg = self::findByOrgAndType($member->org_uuid, self::TYPE_SINGLE_ORG);
        if ($singleOrg !== null && $singleOrg->enabled && Membership::countAcceptedAndConfirmedByUser($member->user_uuid, $member->org_uuid) > 0) {
            throw ApiError::message("Cannot {$action} because the organization policy forbids being part of other organization (membership {$member->uuid})");
        }
    }

    /**
     * @param  list<int>  $statuses
     * @return Collection<int, self>
     */
    private static function ofMemberships(string $userUuid, array $statuses): Collection
    {
        return self::query()
            ->select('org_policies.*')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'org_policies.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->whereIn('users_organizations.status', $statuses)
            ->get();
    }
}
