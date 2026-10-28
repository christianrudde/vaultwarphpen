<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A member of a group (GroupUser in src/db/models/group.rs, the groups_users
 * table). Members are memberships, not users.
 */
final readonly class GroupUser
{
    private const string TABLE = 'groups_users';

    public function __construct(
        public string $groupUuid,
        public string $membershipUuid,
    ) {}

    /**
     * @throws ApiError
     */
    public function save(): void
    {
        $this->updateUserRevision();

        try {
            DB::table(self::TABLE)->insertOrIgnore(['users_organizations_uuid' => $this->membershipUuid, 'groups_uuid' => $this->groupUuid]);
        } catch (QueryException) {
            throw ApiError::message('Error adding user to group');
        }
    }

    /**
     * The group's members of its organization.
     *
     * @return list<self>
     */
    public static function findByGroup(string $groupUuid, string $orgUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('groups', 'groups.uuid', '=', 'groups_users.groups_uuid')
            ->join('users_organizations', function (JoinClause $join): void {
                $join->on('users_organizations.uuid', '=', 'groups_users.users_organizations_uuid')
                    ->on('users_organizations.org_uuid', '=', 'groups.organizations_uuid');
            })
            ->where('groups_users.groups_uuid', $groupUuid)
            ->where('groups.organizations_uuid', $orgUuid)
            ->select('groups_users.*'));
    }

    /**
     * @return list<self>
     */
    public static function findByMember(string $membershipUuid): array
    {
        return self::rows(DB::table(self::TABLE)->where('users_organizations_uuid', $membershipUuid));
    }

    public static function hasAccessToCollectionByMember(string $collectionUuid, string $membershipUuid): bool
    {
        return DB::table(self::TABLE)
            ->join('collections_groups', 'collections_groups.groups_uuid', '=', 'groups_users.groups_uuid')
            ->join('groups', 'groups.uuid', '=', 'groups_users.groups_uuid')
            ->join('collections', function (JoinClause $join): void {
                $join->on('collections.uuid', '=', 'collections_groups.collections_uuid')
                    ->on('collections.org_uuid', '=', 'groups.organizations_uuid');
            })
            ->where('collections_groups.collections_uuid', $collectionUuid)
            ->where('groups_users.users_organizations_uuid', $membershipUuid)
            ->exists();
    }

    /**
     * Whether a group with access to all has the member, whatever its status.
     */
    public static function hasFullAccessByMember(string $orgUuid, string $membershipUuid): bool
    {
        return DB::table(self::TABLE)
            ->join('groups', 'groups.uuid', '=', 'groups_users.groups_uuid')
            ->where('groups.organizations_uuid', $orgUuid)
            ->where('groups.access_all', true)
            ->where('groups_users.users_organizations_uuid', $membershipUuid)
            ->exists();
    }

    public function updateUserRevision(): void
    {
        self::updateRevisionOfMember($this->membershipUuid);
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByGroup(string $groupUuid, string $orgUuid): void
    {
        foreach (self::findByGroup($groupUuid, $orgUuid) as $groupUser) {
            $groupUser->updateUserRevision();
        }

        try {
            DB::table(self::TABLE)->where('groups_uuid', $groupUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting group users');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByMember(string $membershipUuid): void
    {
        self::updateRevisionOfMember($membershipUuid);

        try {
            DB::table(self::TABLE)->where('users_organizations_uuid', $membershipUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting user groups');
        }
    }

    private static function updateRevisionOfMember(string $membershipUuid): void
    {
        $member = Membership::findByUuid($membershipUuid);
        if ($member === null) {
            logger()->warning('Member could not be found!');

            return;
        }
        User::updateUuidRevision($member->user_uuid);
    }

    /**
     * @return list<self>
     */
    private static function rows(Builder $query): array
    {
        $rows = [];
        foreach ($query->get() as $row) {
            if (is_string($row->groups_uuid ?? null) && is_string($row->users_organizations_uuid ?? null)) {
                $rows[] = new self($row->groups_uuid, $row->users_organizations_uuid);
            }
        }

        return $rows;
    }
}
