<?php

namespace App\Models;

use App\Http\ApiError;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A member's access to a collection (CollectionUser in src/db/models/collection.rs,
 * the users_collections table). The swapped variants carry the membership id
 * in place of the user id, which is what the clients address.
 */
final readonly class CollectionUser
{
    private const string TABLE = 'users_collections';

    public function __construct(
        public string $userUuid,
        public string $collectionUuid,
        public bool $readOnly,
        public bool $hidePasswords,
        public bool $manage,
    ) {}

    /**
     * CollectionMembership::to_json_details_for_member, for a swapped row.
     *
     * @return array<string, mixed>
     */
    public function toApiDetailsForMember(int $membershipType): array
    {
        $manage = Membership::typeAtLeast($membershipType, Membership::TYPE_ADMIN)
            || $this->manage
            || ($membershipType === Membership::TYPE_MANAGER && ! $this->readOnly && ! $this->hidePasswords);

        return ['hidePasswords' => $this->hidePasswords, 'id' => $this->userUuid, 'manage' => $manage, 'readOnly' => $this->readOnly];
    }

    /**
     * @return list<self>
     */
    public static function findByOrganizationAndUserUuid(string $orgUuid, string $userUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('collections', 'collections.uuid', '=', 'users_collections.collection_uuid')
            ->where('users_collections.user_uuid', $userUuid)
            ->where('collections.org_uuid', $orgUuid)
            ->select('users_collections.*'));
    }

    /**
     * The organization's collection accesses, by membership id.
     *
     * @return list<self>
     */
    public static function findByOrganizationSwapUserUuidWithMemberUuid(string $orgUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('collections', 'collections.uuid', '=', 'users_collections.collection_uuid')
            ->join('users_organizations', 'users_organizations.user_uuid', '=', 'users_collections.user_uuid')
            ->where('collections.org_uuid', $orgUuid)
            ->where('users_organizations.org_uuid', $orgUuid)
            ->select('users_organizations.uuid as user_uuid', 'users_collections.collection_uuid', 'users_collections.read_only',
                'users_collections.hide_passwords', 'users_collections.manage'));
    }

    /**
     * One collection's accesses, by membership id.
     *
     * @return list<self>
     */
    public static function findByOrgAndCollSwapUserUuidWithMemberUuid(string $orgUuid, string $collectionUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('users_organizations', 'users_organizations.user_uuid', '=', 'users_collections.user_uuid')
            ->where('users_collections.collection_uuid', $collectionUuid)
            ->where('users_organizations.org_uuid', $orgUuid)
            ->select('users_organizations.uuid as user_uuid', 'users_collections.collection_uuid', 'users_collections.read_only',
                'users_collections.hide_passwords', 'users_collections.manage'));
    }

    /**
     * @return list<self>
     */
    public static function findByCollection(string $collectionUuid): array
    {
        return self::rows(DB::table(self::TABLE)->where('collection_uuid', $collectionUuid));
    }

    public static function findByCollectionAndUser(string $collectionUuid, string $userUuid): ?self
    {
        return self::rows(DB::table(self::TABLE)->where('collection_uuid', $collectionUuid)->where('user_uuid', $userUuid)->limit(1))[0] ?? null;
    }

    public static function hasAccessToCollectionByUser(string $collectionUuid, string $userUuid): bool
    {
        return self::findByCollectionAndUser($collectionUuid, $userUuid) !== null;
    }

    /**
     * The user's accesses in organizations where the membership is confirmed.
     *
     * @return list<self>
     */
    public static function findByUser(string $userUuid): array
    {
        return self::rows(DB::table(self::TABLE)
            ->join('collections', 'collections.uuid', '=', 'users_collections.collection_uuid')
            ->join('users_organizations', function (JoinClause $join): void {
                $join->on('users_organizations.org_uuid', '=', 'collections.org_uuid')
                    ->on('users_organizations.user_uuid', '=', 'users_collections.user_uuid');
            })
            ->where('users_collections.user_uuid', $userUuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->select('users_collections.*'));
    }

    /**
     * @throws ApiError
     */
    public static function save(string $userUuid, string $collectionUuid, bool $readOnly, bool $hidePasswords, bool $manage): void
    {
        User::updateUuidRevision($userUuid);
        try {
            DB::table(self::TABLE)->upsert(
                [['user_uuid' => $userUuid, 'collection_uuid' => $collectionUuid, 'read_only' => $readOnly, 'hide_passwords' => $hidePasswords, 'manage' => $manage]],
                ['user_uuid', 'collection_uuid'],
                ['read_only', 'hide_passwords', 'manage'],
            );
        } catch (QueryException) {
            throw ApiError::message('Error adding user to collection');
        }
    }

    /**
     * @throws ApiError
     */
    public function delete(): void
    {
        User::updateUuidRevision($this->userUuid);
        try {
            DB::table(self::TABLE)->where('user_uuid', $this->userUuid)->where('collection_uuid', $this->collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error removing user from collection');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByCollection(string $collectionUuid): void
    {
        foreach (self::findByCollection($collectionUuid) as $access) {
            User::updateUuidRevision($access->userUuid);
        }
        try {
            DB::table(self::TABLE)->where('collection_uuid', $collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting users from collection');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByUserAndOrg(string $userUuid, string $orgUuid): void
    {
        foreach (self::findByOrganizationAndUserUuid($orgUuid, $userUuid) as $access) {
            try {
                DB::table(self::TABLE)->where('user_uuid', $userUuid)->where('collection_uuid', $access->collectionUuid)->delete();
            } catch (QueryException) {
                throw ApiError::message('Error removing user from collections');
            }
        }
    }

    /**
     * @return list<self>
     */
    private static function rows(Builder $query): array
    {
        $rows = [];
        foreach ($query->get() as $row) {
            if (is_string($row->user_uuid ?? null) && is_string($row->collection_uuid ?? null)) {
                // SQLite and MySQL return booleans as numbers.
                $rows[] = new self($row->user_uuid, $row->collection_uuid, (bool) $row->read_only, (bool) $row->hide_passwords, (bool) $row->manage);
            }
        }

        return $rows;
    }
}
