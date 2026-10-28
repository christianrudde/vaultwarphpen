<?php

namespace App\Models;

use App\Http\ApiError;
use App\Http\Json;
use App\Support\Random;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A collection of an organization (Collection in src/db/models/collection.rs;
 * renamed so that it does not shadow Eloquent's collections). Access through
 * groups is not ported yet; the queries follow upstream without groups.
 *
 * @property string $uuid
 * @property string $org_uuid
 * @property string $name
 * @property ?string $external_id
 */
final class OrgCollection extends Model
{
    protected $table = 'collections';

    protected $primaryKey = 'uuid';

    public static function new(string $orgUuid, string $name, ?string $externalId): self
    {
        return new self([
            'uuid' => Random::uuid(),
            'org_uuid' => $orgUuid,
            'name' => $name,
            // No empty strings in the database.
            'external_id' => $externalId === '' ? null : $externalId,
        ]);
    }

    /**
     * Collection::to_json
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return Json::sorted([
            'externalId' => $this->external_id,
            'id' => $this->uuid,
            'organizationId' => $this->org_uuid,
            'name' => $this->name,
            // A shared collection; default user collections are not supported.
            'type' => 0,
            'defaultUserCollectionEmail' => null,
            'object' => 'collection',
        ]);
    }

    /**
     * Collection::to_json_details: the user's own restrictions on the collection.
     *
     * @param  array{members: array<string, Membership>, userCollections: array<string, CollectionUser>}|null  $sync
     * @return array<string, mixed>
     */
    public function toApiDetails(string $userUuid, ?array $sync = null): array
    {
        if ($sync !== null) {
            $member = $sync['members'][$this->org_uuid] ?? null;
            if ($member === null) {
                [$readOnly, $hidePasswords, $manage] = [true, true, false];
            } elseif ($member->hasFullAccess()) {
                // Owners and admins always manage; of full access members, managers too.
                [$readOnly, $hidePasswords, $manage] = [false, false, $member->atLeast(Membership::TYPE_MANAGER)];
            } else {
                $access = $sync['userCollections'][$this->uuid] ?? null;
                $isManager = $member->atype === Membership::TYPE_MANAGER;
                [$readOnly, $hidePasswords, $manage] = $access === null
                    ? [false, false, false]
                    : [$access->readOnly, $access->hidePasswords, $isManager && ($access->manage || (! $access->readOnly && ! $access->hidePasswords))];
            }
        } else {
            $member = Membership::findConfirmedByUserAndOrg($userUuid, $this->org_uuid);
            if ($member === null) {
                [$readOnly, $hidePasswords, $manage] = [true, true, false];
            } elseif ($member->hasFullAccess()) {
                [$readOnly, $hidePasswords, $manage] = [false, false, $member->atLeast(Membership::TYPE_MANAGER)];
            } elseif ($member->atype === Membership::TYPE_MANAGER && $this->isManageableByUser($userUuid)) {
                [$readOnly, $hidePasswords, $manage] = [false, false, true];
            } else {
                $readOnly = ! $this->isWritableByUser($userUuid);
                $hidePasswords = $this->hidePasswordsForUser($userUuid);
                $manage = $member->atype === Membership::TYPE_MANAGER && ! $readOnly && ! $hidePasswords;
            }
        }

        return Json::sorted([
            ...$this->toApi(),
            'object' => 'collectionDetails',
            'readOnly' => $readOnly,
            'hidePasswords' => $hidePasswords,
            'manage' => $manage,
        ]);
    }

    /**
     * Collection::can_access_collection
     */
    public static function canAccessCollection(Membership $member, string $collectionUuid): bool
    {
        return $member->status === Membership::STATUS_CONFIRMED
            && ($member->hasFullAccess() || CollectionUser::hasAccessToCollectionByUser($collectionUuid, $member->user_uuid));
    }

    /**
     * Saving tells the clients of everyone with access to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->updateUsersRevision();
        $this->upsertRow(['uuid'], 'Error saving collection');

        return true;
    }

    /**
     * With its cipher links and accesses.
     *
     * @throws ApiError
     */
    public function deleteCollection(): void
    {
        $this->updateUsersRevision();
        CollectionCipher::deleteAllByCollection($this->uuid);
        CollectionUser::deleteAllByCollection($this->uuid);
        self::deleteGroupAccesses($this->uuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting collection');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByOrganization(string $orgUuid): void
    {
        foreach (self::findByOrganization($orgUuid) as $collection) {
            $collection->deleteCollection();
        }
    }

    public function updateUsersRevision(): void
    {
        foreach (Membership::findByCollectionAndOrg($this->uuid, $this->org_uuid) as $member) {
            User::updateUuidRevision($member->user_uuid);
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

    /**
     * The collections the user reaches: directly, or with access to all of a
     * confirmed membership.
     *
     * @return Collection<int, self>
     */
    public static function findByUserUuid(string $userUuid): Collection
    {
        return self::withAccessOf($userUuid)
            ->where(static fn (Builder $query) => $query->where('users_collections.user_uuid', $userUuid)->orWhere('users_organizations.access_all', true))
            ->select('collections.*')
            ->distinct()
            ->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByOrganizationAndUserUuid(string $orgUuid, string $userUuid): Collection
    {
        return self::findByUserUuid($userUuid)->filter(static fn (self $collection): bool => $collection->org_uuid === $orgUuid)->values();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByOrganization(string $orgUuid): Collection
    {
        return self::query()->where('org_uuid', $orgUuid)->get();
    }

    /**
     * The collection, if the user reaches it, or is admin or owner of its organization.
     */
    public static function findByUuidAndUser(string $uuid, string $userUuid): ?self
    {
        return self::withAccessOf($userUuid)
            ->where('collections.uuid', $uuid)
            ->where(static fn (Builder $query) => $query->where('users_collections.collection_uuid', $uuid)
                ->orWhere('users_organizations.access_all', true)
                ->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN))
            ->select('collections.*')
            ->first();
    }

    public function isWritableByUser(string $userUuid): bool
    {
        return self::query()
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('collections.org_uuid', '=', 'users_organizations.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid)
                    ->where('users_organizations.status', '=', Membership::STATUS_CONFIRMED);
            })
            ->leftJoin('users_collections', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_collections.collection_uuid', '=', 'collections.uuid')
                    ->where('users_collections.user_uuid', '=', $userUuid);
            })
            ->where('collections.uuid', $this->uuid)
            ->where(fn (Builder $query) => $query->where('users_organizations.atype', '<=', Membership::TYPE_ADMIN)
                ->orWhere('users_organizations.access_all', true)
                ->orWhere(fn (Builder $query) => $query->where('users_collections.collection_uuid', $this->uuid)->where('users_collections.read_only', false)))
            ->exists();
    }

    /**
     * True for admins and members with access to all as well; upstream's
     * callers only ask for others.
     */
    public function hidePasswordsForUser(string $userUuid): bool
    {
        return self::withAccessOf($userUuid)
            ->where('collections.uuid', $this->uuid)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->where('users_collections.collection_uuid', $this->uuid)->where('users_collections.hide_passwords', true))
                ->orWhere('users_organizations.access_all', true)
                ->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN))
            ->exists();
    }

    public static function isCollManageableByUser(string $uuid, string $userUuid): bool
    {
        return self::withAccessOf($userUuid)
            ->where('collections.uuid', $uuid)
            ->where(static fn (Builder $query) => $query
                ->where(static fn (Builder $query) => $query->where('users_collections.collection_uuid', $uuid)->where('users_collections.manage', true))
                ->orWhere('users_organizations.access_all', true)
                ->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN))
            ->exists();
    }

    public function isManageableByUser(string $userUuid): bool
    {
        return self::isCollManageableByUser($this->uuid, $userUuid);
    }

    /**
     * Collections joined with the user's direct access and confirmed membership.
     *
     * @return Builder<self>
     */
    private static function withAccessOf(string $userUuid): Builder
    {
        return self::query()
            ->leftJoin('users_collections', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_collections.collection_uuid', '=', 'collections.uuid')
                    ->where('users_collections.user_uuid', '=', $userUuid);
            })
            ->leftJoin('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('collections.org_uuid', '=', 'users_organizations.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED);
    }

    /**
     * CollectionGroup::delete_all_by_collection
     *
     * @throws ApiError
     */
    private static function deleteGroupAccesses(string $collectionUuid): void
    {
        try {
            DB::table('collections_groups')->where('collections_uuid', $collectionUuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting collection group');
        }
    }
}
