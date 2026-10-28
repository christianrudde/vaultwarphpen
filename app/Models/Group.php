<?php

namespace App\Models;

use App\Config\Config;
use App\Http\ApiError;
use App\Http\Json;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\Random;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * A group of an organization's members (Group in src/db/models/group.rs).
 * Its collection accesses are CollectionGroup rows, its members GroupUser rows.
 *
 * @property string $uuid
 * @property string $organizations_uuid
 * @property string $name
 * @property bool $access_all
 * @property ?string $external_id
 * @property DateTimeImmutable $creation_date
 * @property DateTimeImmutable $revision_date
 */
final class Group extends Model
{
    protected $table = 'groups';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'access_all' => 'boolean',
            'creation_date' => NaiveDateTime::class,
            'revision_date' => NaiveDateTime::class,
        ];
    }

    /**
     * ORG_GROUPS_ENABLED; without it the clients see no groups.
     */
    public static function enabled(): bool
    {
        return app(Config::class)->bool('org_groups_enabled');
    }

    public static function new(string $orgUuid, string $name, bool $accessAll, ?string $externalId): self
    {
        $now = Dates::now();
        $group = new self([
            'uuid' => Random::uuid(),
            'organizations_uuid' => $orgUuid,
            'name' => $name,
            'access_all' => $accessAll,
            'external_id' => null,
            'creation_date' => $now,
            'revision_date' => $now,
        ]);
        $group->setExternalId($externalId);

        return $group;
    }

    /**
     * No blank external ids in the database.
     */
    public function setExternalId(?string $externalId): void
    {
        $this->external_id = $externalId !== null && trim($externalId) !== '' ? $externalId : null;
    }

    /**
     * Group::to_json
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return Json::sorted([
            'id' => $this->uuid,
            'organizationId' => $this->organizations_uuid,
            'name' => $this->name,
            'externalId' => $this->external_id,
            'object' => 'group',
        ]);
    }

    /**
     * Group::to_json_details: with the group's collection accesses.
     *
     * @return array<string, mixed>
     */
    public function toApiDetails(): array
    {
        $collections = array_map(static fn (CollectionGroup $access): array => Json::sorted([
            'id' => $access->collectionUuid,
            'readOnly' => $access->readOnly,
            'hidePasswords' => $access->hidePasswords,
            'manage' => $access->manage,
        ]), CollectionGroup::findByGroup($this->uuid, $this->organizations_uuid));

        return Json::sorted([
            ...$this->toApi(),
            'collections' => $collections,
            'object' => 'groupDetails',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->revision_date = Dates::now();
        $this->upsertRow(['uuid'], 'Error saving group');

        return true;
    }

    /**
     * With its collection accesses and members.
     *
     * @throws ApiError
     */
    public function deleteGroup(string $orgUuid): void
    {
        CollectionGroup::deleteAllByGroup($this->uuid, $orgUuid);
        GroupUser::deleteAllByGroup($this->uuid, $orgUuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting group');
        }
    }

    /**
     * @throws ApiError
     */
    public static function deleteAllByOrganization(string $orgUuid): void
    {
        foreach (self::findByOrganization($orgUuid) as $group) {
            $group->deleteGroup($orgUuid);
        }
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByOrganization(string $orgUuid): Collection
    {
        return self::query()->where('organizations_uuid', $orgUuid)->get();
    }

    public static function countByOrg(string $orgUuid): int
    {
        return self::query()->where('organizations_uuid', $orgUuid)->count();
    }

    public static function findByUuidAndOrg(string $uuid, string $orgUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('organizations_uuid', $orgUuid)->first();
    }

    public static function findByExternalIdAndOrg(string $externalId, string $orgUuid): ?self
    {
        return self::query()->where('external_id', $externalId)->where('organizations_uuid', $orgUuid)->first();
    }

    /**
     * Group::get_orgs_by_user_with_full_access: the organizations where a
     * group with access to all gives the user's confirmed membership full access.
     *
     * @return list<string>
     */
    public static function orgsByUserWithFullAccess(string $userUuid): array
    {
        $ids = DB::table('groups_users')
            ->join('users_organizations', 'users_organizations.uuid', '=', 'groups_users.users_organizations_uuid')
            ->join('groups', function (JoinClause $join): void {
                $join->on('groups.uuid', '=', 'groups_users.groups_uuid')
                    ->on('groups.organizations_uuid', '=', 'users_organizations.org_uuid');
            })
            ->where('users_organizations.user_uuid', $userUuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->where('groups.access_all', true)
            ->distinct()
            ->pluck('groups.organizations_uuid');

        return array_values(array_filter($ids->all(), is_string(...)));
    }

    /**
     * Group::is_in_full_access_group: only through a confirmed membership, as
     * group members stay in their groups when revoked.
     */
    public static function isInFullAccessGroup(string $userUuid, string $orgUuid): bool
    {
        return DB::table('groups')
            ->join('groups_users', 'groups_users.groups_uuid', '=', 'groups.uuid')
            ->join('users_organizations', function (JoinClause $join): void {
                $join->on('users_organizations.uuid', '=', 'groups_users.users_organizations_uuid')
                    ->on('users_organizations.org_uuid', '=', 'groups.organizations_uuid');
            })
            ->where('users_organizations.user_uuid', $userUuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->where('groups.organizations_uuid', $orgUuid)
            ->where('groups.access_all', true)
            ->exists();
    }
}
