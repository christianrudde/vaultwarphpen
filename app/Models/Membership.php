<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Support\Random;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A user's membership in an organization (Membership in
 * src/db/models/organization.rs). Revoking keeps the previous status, lowered
 * by 128.
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

    public static function new(string $userUuid, string $orgUuid, ?string $invitedByEmail): self
    {
        return new self([
            'uuid' => Random::uuid(),
            'user_uuid' => $userUuid,
            'org_uuid' => $orgUuid,
            'invited_by_email' => $invitedByEmail,
            'access_all' => false,
            'akey' => '',
            'status' => self::STATUS_ACCEPTED,
            'atype' => self::TYPE_USER,
            'reset_password_key' => null,
            'external_id' => null,
        ]);
    }

    /**
     * MembershipType::from_str: a number or a name; the custom role (4) becomes a manager.
     */
    public static function typeFromString(string $type): ?int
    {
        return match ($type) {
            '0', 'Owner' => self::TYPE_OWNER,
            '1', 'Admin' => self::TYPE_ADMIN,
            '2', 'User' => self::TYPE_USER,
            '3', 'Manager', '4', 'Custom' => self::TYPE_MANAGER,
            default => null,
        };
    }

    /**
     * `type >= other` of MembershipType, by access; unknown types are below nothing.
     */
    public static function typeAtLeast(int $type, int $other): bool
    {
        $own = self::ACCESS_LEVEL[$type] ?? null;

        return $own !== null && $own >= self::ACCESS_LEVEL[$other];
    }

    /**
     * `self.atype < other`: an i32 that is no type counts as below.
     */
    public function typeBelow(int $type): bool
    {
        $own = self::ACCESS_LEVEL[$this->atype] ?? null;

        return $own === null || $own < self::ACCESS_LEVEL[$type];
    }

    public function atLeast(int $type): bool
    {
        return self::typeAtLeast($this->atype, $type);
    }

    public function hasFullAccess(): bool
    {
        return ($this->access_all || $this->atLeast(self::TYPE_ADMIN)) && $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * The custom role is stored as a manager and shown as custom again.
     */
    public function typeManagerAsCustom(): int
    {
        return $this->atype === self::TYPE_MANAGER ? 4 : $this->atype;
    }

    /**
     * The status clients see: -1 for every revoked one.
     */
    public function displayStatus(): int
    {
        return $this->status < self::STATUS_REVOKED ? self::STATUS_REVOKED : $this->status;
    }

    public function unrevokedStatus(): int
    {
        return $this->status <= self::STATUS_REVOKED ? $this->status + self::ACTIVATE_REVOKE_DIFF : $this->status;
    }

    public function restore(): bool
    {
        if ($this->status < self::STATUS_INVITED) {
            $this->status += self::ACTIVATE_REVOKE_DIFF;

            return true;
        }

        return false;
    }

    /**
     * Membership::to_json, the ProfileOrganizationResponseModel.
     *
     * @return array<string, mixed>
     */
    public function toApiProfile(): array
    {
        $org = Organization::findByUuid($this->org_uuid) ?? throw new \LogicException("Organization {$this->org_uuid} of membership {$this->uuid} is missing");
        $config = app(Config::class);
        $type = $this->typeManagerAsCustom();
        // The three collection roles of a custom user stand for access to all.
        $customAll = $type === 4 && $this->access_all;

        return Json::sorted([
            'id' => $this->org_uuid,
            'identifier' => null,
            'name' => $org->name,
            // The web vault's maxEmailsCount.
            'seats' => 20,
            'maxCollections' => null,
            'usersGetPremium' => true,
            'use2fa' => true,
            'useDirectory' => false,
            'useEvents' => $config->bool('org_events_enabled'),
            'useGroups' => $config->bool('org_groups_enabled'),
            'useTotp' => true,
            'useScim' => false,
            'usePolicies' => true,
            'useApi' => true,
            'selfHost' => true,
            'hasPublicAndPrivateKeys' => $org->private_key !== null && $org->public_key !== null,
            'resetPasswordEnrolled' => $this->reset_password_key !== null,
            'useResetPassword' => $config->mailEnabled(),
            'ssoBound' => false,
            'useSso' => false,
            'useKeyConnector' => false,
            'useSecretsManager' => false,
            'usePasswordManager' => true,
            'useCustomPermissions' => true,
            'useActivateAutofillPolicy' => false,
            'useAdminSponsoredFamilies' => false,
            'useRiskInsights' => false,
            'useDisableSMAdsForUsers' => true,
            'useInviteLinks' => false,
            'useMyItems' => false,
            'useOrganizationDomains' => false,
            'usePam' => false,
            'usePhishingBlocker' => false,
            'organizationUserId' => $this->uuid,
            'providerId' => null,
            'providerName' => null,
            'providerType' => null,
            'familySponsorshipFriendlyName' => null,
            'familySponsorshipAvailable' => false,
            // The enterprise tier.
            'productTierType' => 3,
            'keyConnectorEnabled' => false,
            'keyConnectorUrl' => null,
            'familySponsorshipLastSyncDate' => null,
            'familySponsorshipValidUntil' => null,
            'familySponsorshipToDelete' => null,
            'accessSecretsManager' => false,
            // Only managers with access to all may create collections.
            'limitCollectionCreation' => $this->typeBelow(self::TYPE_MANAGER) || ! $this->access_all,
            'limitCollectionDeletion' => true,
            'limitItemDeletion' => false,
            'allowAdminAccessToAllCollectionItems' => true,
            'userIsClaimedByOrganization' => false,
            'permissions' => self::permissions($customAll),
            'maxStorageGb' => 32767,
            'userId' => $this->user_uuid,
            // Accepted members have no key yet.
            'key' => $this->akey === '' || $this->akey === null ? null : $this->akey,
            'status' => $this->status,
            'type' => $type,
            'enabled' => true,
            'object' => 'profileOrganization',
        ]);
    }

    /**
     * Membership::to_json_details_for_admin: how admins see a member.
     *
     * @return array<string, mixed>
     */
    public function toApiDetailsForAdmin(bool $includeCollections, bool $includeGroups): array
    {
        $user = User::findByUuid($this->user_uuid) ?? throw new \LogicException("User {$this->user_uuid} of membership {$this->uuid} is missing");

        $groups = $includeGroups && Group::enabled()
            ? array_map(static fn (GroupUser $groupUser): string => $groupUser->groupUuid, GroupUser::findByMember($this->uuid))
            : [];
        // Whatever the member's status: admins look at other members here.
        $fullAccessGroup = Group::enabled() && GroupUser::hasFullAccessByMember($this->org_uuid, $this->uuid);

        // Only the collections assigned to the member itself, and none with
        // full access; those of groups come with the groups.
        $collections = [];
        if ($includeCollections && ! $fullAccessGroup && ! $this->access_all) {
            foreach (CollectionUser::findByOrganizationAndUserUuid($this->org_uuid, $this->user_uuid) as $access) {
                $collections[] = [
                    'hidePasswords' => $access->hidePasswords,
                    'id' => $access->collectionUuid,
                    'manage' => $access->manage || ($this->atype === self::TYPE_MANAGER && ! $access->readOnly && ! $access->hidePasswords),
                    'readOnly' => $access->readOnly,
                ];
            }
        }
        $type = $this->typeManagerAsCustom();

        return Json::sorted([
            'id' => $this->uuid,
            'userId' => $this->user_uuid,
            'name' => $this->unrevokedStatus() >= self::STATUS_ACCEPTED ? $user->name : null,
            'email' => $user->email,
            'externalId' => $this->external_id,
            'avatarColor' => $user->avatar_color,
            'groups' => $groups,
            'collections' => $collections,
            'status' => $this->displayStatus(),
            'type' => $type,
            'twoFactorEnabled' => TwoFactor::findByUser($user->uuid)->isNotEmpty(),
            'resetPasswordEnrolled' => $this->reset_password_key !== null,
            'hasMasterPassword' => $user->password_hash !== '',
            // Only a custom user with access to all gets permissions; clients assume false otherwise.
            'permissions' => $type === 4 && $this->access_all ? self::permissions(true) : null,
            'ssoBound' => false,
            'claimedByOrganization' => false,
            'usesKeyConnector' => false,
            'accessSecretsManager' => false,
            'object' => 'organizationUserUserDetails',
        ]);
    }

    /**
     * Membership::to_json_mini_details
     *
     * @return array<string, mixed>
     */
    public function toApiMiniDetails(): array
    {
        $user = User::findByUuid($this->user_uuid) ?? throw new \LogicException("User {$this->user_uuid} of membership {$this->uuid} is missing");

        return Json::sorted([
            'id' => $this->uuid,
            'userId' => $this->user_uuid,
            'type' => $this->typeManagerAsCustom(),
            'status' => $this->displayStatus(),
            'name' => $user->name,
            'email' => $user->email,
            'object' => 'organizationUserUserMiniDetails',
        ]);
    }

    /**
     * Membership::to_json_user_access_restrictions
     *
     * @return array<string, mixed>
     */
    public function toApiUserAccessRestrictions(CollectionUser $access): array
    {
        return ['hidePasswords' => $access->hidePasswords, 'id' => $this->uuid, 'manage' => $access->manage, 'readOnly' => $access->readOnly];
    }

    /**
     * The custom permissions; the collection roles mirror access to all.
     *
     * @return array<string, bool>
     */
    private static function permissions(bool $collections): array
    {
        return [
            'accessEventLogs' => false,
            'accessImportExport' => false,
            'accessReports' => false,
            'createNewCollections' => $collections,
            'deleteAnyCollection' => $collections,
            'editAnyCollection' => $collections,
            'manageGroups' => false,
            'managePolicies' => false,
            'manageResetPassword' => false,
            'manageScim' => false,
            'manageSso' => false,
            'manageUsers' => false,
        ];
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
     * With its collection accesses and group memberships.
     *
     * @throws ApiError
     */
    public function deleteMembership(): void
    {
        User::updateUuidRevision($this->user_uuid);
        CollectionUser::deleteAllByUserAndOrg($this->user_uuid, $this->org_uuid);
        GroupUser::deleteAllByMember($this->uuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing user from organization');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByOrganization(string $orgUuid): void
    {
        foreach (self::findByOrg($orgUuid) as $member) {
            $member->deleteMembership();
        }
    }

    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
    }

    public static function findByUuidAndOrg(string $uuid, string $orgUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('org_uuid', $orgUuid)->first();
    }

    public static function findByUserAndOrg(string $userUuid, string $orgUuid): ?self
    {
        return self::query()->where('user_uuid', $userUuid)->where('org_uuid', $orgUuid)->first();
    }

    public static function findConfirmedByUserAndOrg(string $userUuid, string $orgUuid): ?self
    {
        return self::query()->where('user_uuid', $userUuid)->where('org_uuid', $orgUuid)->where('status', self::STATUS_CONFIRMED)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findConfirmedByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->where('status', self::STATUS_CONFIRMED)->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findAcceptedAndConfirmedByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->whereIn('status', [self::STATUS_ACCEPTED, self::STATUS_CONFIRMED])->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findInvitedByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->where('status', self::STATUS_INVITED)->get();
    }

    public static function countAcceptedAndConfirmedByUser(string $userUuid, string $excludedOrgUuid): int
    {
        return self::query()->where('user_uuid', $userUuid)->where('org_uuid', '!=', $excludedOrgUuid)
            ->whereIn('status', [self::STATUS_ACCEPTED, self::STATUS_CONFIRMED])->count();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByOrg(string $orgUuid): Collection
    {
        return self::query()->where('org_uuid', $orgUuid)->get();
    }

    /**
     * Confirmed owners and admins, and managers with access to all.
     *
     * @return Collection<int, self>
     */
    public static function findConfirmedAndManageAllByOrg(string $orgUuid): Collection
    {
        return self::query()->where('org_uuid', $orgUuid)->where('status', self::STATUS_CONFIRMED)
            ->where(static fn ($query) => $query->whereIn('atype', [self::TYPE_OWNER, self::TYPE_ADMIN])
                ->orWhere(static fn ($query) => $query->where('atype', self::TYPE_MANAGER)->where('access_all', true)))
            ->get();
    }

    public static function countConfirmedByOrgAndType(string $orgUuid, int $type): int
    {
        return self::query()->where('org_uuid', $orgUuid)->where('atype', $type)->where('status', self::STATUS_CONFIRMED)->count();
    }

    /**
     * Members with access to all, or to the collection itself.
     *
     * @return Collection<int, self>
     */
    public static function findByCollectionAndOrg(string $collectionUuid, string $orgUuid): Collection
    {
        return self::query()
            ->select('users_organizations.*')
            ->leftJoin('users_collections', 'users_collections.user_uuid', '=', 'users_organizations.user_uuid')
            ->where('users_organizations.org_uuid', $orgUuid)
            ->where(static fn ($query) => $query->where('users_organizations.access_all', true)->orWhere('users_collections.collection_uuid', $collectionUuid))
            ->get();
    }

    /**
     * Confirmed members who reach the cipher: with access to all, or through
     * one of its collections.
     *
     * @return Collection<int, self>
     */
    public static function findByCipherAndOrg(string $cipherUuid, string $orgUuid): Collection
    {
        return self::query()
            ->select('users_organizations.*')
            ->leftJoin('users_collections', 'users_collections.user_uuid', '=', 'users_organizations.user_uuid')
            ->leftJoin('ciphers_collections', function (JoinClause $join) use ($cipherUuid): void {
                $join->on('ciphers_collections.collection_uuid', '=', 'users_collections.collection_uuid')
                    ->where('ciphers_collections.cipher_uuid', '=', $cipherUuid);
            })
            ->where('users_organizations.org_uuid', $orgUuid)
            ->where('users_organizations.status', self::STATUS_CONFIRMED)
            ->where(static fn ($query) => $query->where('users_organizations.access_all', true)->orWhere('ciphers_collections.cipher_uuid', $cipherUuid))
            ->distinct()
            ->get();
    }

    /**
     * The confirmed members who reach the cipher through a group: one with
     * access to all, or with a collection of the cipher.
     *
     * @return Collection<int, self>
     */
    public static function findByCipherAndOrgWithGroup(string $cipherUuid, string $orgUuid): Collection
    {
        return self::query()
            ->select('users_organizations.*')
            ->join('groups_users', 'groups_users.users_organizations_uuid', '=', 'users_organizations.uuid')
            ->leftJoin('collections_groups', 'collections_groups.groups_uuid', '=', 'groups_users.groups_uuid')
            ->leftJoin('groups', function (JoinClause $join): void {
                $join->on('groups.uuid', '=', 'groups_users.groups_uuid')
                    ->on('groups.organizations_uuid', '=', 'users_organizations.org_uuid');
            })
            ->leftJoin('ciphers_collections', function (JoinClause $join) use ($cipherUuid): void {
                $join->on('ciphers_collections.collection_uuid', '=', 'collections_groups.collections_uuid')
                    ->where('ciphers_collections.cipher_uuid', '=', $cipherUuid);
            })
            ->where('users_organizations.org_uuid', $orgUuid)
            ->where('users_organizations.status', self::STATUS_CONFIRMED)
            ->where(static fn ($query) => $query->where('groups.access_all', true)->orWhere('ciphers_collections.cipher_uuid', $cipherUuid))
            ->distinct()
            ->get();
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
