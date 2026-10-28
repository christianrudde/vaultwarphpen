<?php

namespace App\Models;

use App\Http\ApiError;
use App\Http\Json;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A group's access to a collection (CollectionGroup in
 * src/db/models/group.rs, the collections_groups table).
 */
final readonly class CollectionGroup
{
    private const string TABLE = 'collections_groups';

    public function __construct(
        public string $collectionUuid,
        public string $groupUuid,
        public bool $readOnly,
        public bool $hidePasswords,
        public bool $manage,
    ) {}

    /**
     * CollectionGroup::to_json_details_for_group: the stored manage flag.
     *
     * @return array<string, mixed>
     */
    public function toApiDetailsForGroup(): array
    {
        return Json::sorted([
            'id' => $this->groupUuid,
            'readOnly' => $this->readOnly,
            'hidePasswords' => $this->hidePasswords,
            'manage' => $this->manage,
        ]);
    }

    /**
     * Tells the clients of the group's members to sync.
     *
     * @throws ApiError
     */
    public function save(string $orgUuid): void
    {
        foreach (GroupUser::findByGroup($this->groupUuid, $orgUuid) as $groupUser) {
            $groupUser->updateUserRevision();
        }

        try {
            DB::table(self::TABLE)->upsert(
                [['collections_uuid' => $this->collectionUuid, 'groups_uuid' => $this->groupUuid, 'read_only' => $this->readOnly, 'hide_passwords' => $this->hidePasswords, 'manage' => $this->manage]],
                ['collections_uuid', 'groups_uuid'],
                ['read_only', 'hide_passwords', 'manage'],
            );
        } catch (QueryException) {
            throw ApiError::message('Error adding group to collection');
        }
    }

    /**
     * The group's accesses to collections of the organization.
     *
     * @return list<self>
     */
    public static function findByGroup(string $groupUuid, string $orgUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('groups', 'groups.uuid', '=', 'collections_groups.groups_uuid')
            ->join('collections', function (JoinClause $join): void {
                $join->on('collections.uuid', '=', 'collections_groups.collections_uuid')
                    ->on('collections.org_uuid', '=', 'groups.organizations_uuid');
            })
            ->where('collections_groups.groups_uuid', $groupUuid)
            ->where('collections.org_uuid', $orgUuid)
            ->select('collections_groups.*'));
    }

    /**
     * The accesses of the groups of the user's confirmed memberships.
     *
     * @return list<self>
     */
    public static function findByUser(string $userUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('groups_users', 'groups_users.groups_uuid', '=', 'collections_groups.groups_uuid')
            ->join('users_organizations', 'users_organizations.uuid', '=', 'groups_users.users_organizations_uuid')
            ->join('groups', function (JoinClause $join): void {
                $join->on('groups.uuid', '=', 'collections_groups.groups_uuid')
                    ->on('groups.organizations_uuid', '=', 'users_organizations.org_uuid');
            })
            ->join('collections', function (JoinClause $join): void {
                $join->on('collections.uuid', '=', 'collections_groups.collections_uuid')
                    ->on('collections.org_uuid', '=', 'groups.organizations_uuid');
            })
            ->where('users_organizations.user_uuid', $userUuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->select('collections_groups.*'));
    }

    /**
     * The groups' accesses to a collection, of groups of its organization.
     *
     * @return list<self>
     */
    public static function findByCollection(string $collectionUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('collections', 'collections.uuid', '=', 'collections_groups.collections_uuid')
            ->join('groups', function (JoinClause $join): void {
                $join->on('groups.uuid', '=', 'collections_groups.groups_uuid')
                    ->on('groups.organizations_uuid', '=', 'collections.org_uuid');
            })
            ->where('collections_groups.collections_uuid', $collectionUuid)
            ->select('collections_groups.*'));
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByGroup(string $groupUuid, string $orgUuid): void
    {
        foreach (GroupUser::findByGroup($groupUuid, $orgUuid) as $groupUser) {
            $groupUser->updateUserRevision();
        }

        try {
            DB::table(self::TABLE)->where('groups_uuid', $groupUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting collection group');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCollection(string $collectionUuid, string $orgUuid): void
    {
        foreach (self::findByCollection($collectionUuid) as $access) {
            foreach (GroupUser::findByGroup($access->groupUuid, $orgUuid) as $groupUser) {
                $groupUser->updateUserRevision();
            }
        }

        try {
            DB::table(self::TABLE)->where('collections_uuid', $collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting collection group');
        }
    }

    /**
     * @return list<self>
     */
    private static function rows(Builder $query): array
    {
        $rows = [];
        foreach ($query->get() as $row) {
            if (is_string($row->collections_uuid ?? null) && is_string($row->groups_uuid ?? null)) {
                // SQLite and MySQL return booleans as numbers.
                $rows[] = new self($row->collections_uuid, $row->groups_uuid, (bool) $row->read_only, (bool) $row->hide_passwords, (bool) $row->manage);
            }
        }

        return $rows;
    }
}
