<?php

namespace App\Models;

use App\Http\ApiError;
use App\Http\Json;
use App\Models\Casts\NaiveDateTime;
use App\Support\Dates;
use App\Support\JsonValue;
use App\Support\Random;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * A vault item (src/db/models/cipher.rs). Organization ciphers are not ported
 * yet: members of an organization get an error instead of a partial vault.
 *
 * @property string $uuid
 * @property DateTimeImmutable $created_at
 * @property DateTimeImmutable $updated_at
 * @property ?string $user_uuid
 * @property ?string $organization_uuid
 * @property int $atype
 * @property string $name
 * @property ?string $notes
 * @property ?string $fields
 * @property string $data
 * @property ?string $password_history
 * @property ?DateTimeImmutable $deleted_at
 * @property ?int $reprompt
 * @property ?string $key
 */
final class Cipher extends Model
{
    /**
     * The key of the type-specific data in the API, by cipher type.
     */
    public const array TYPE_KEYS = [
        1 => 'login',
        2 => 'secureNote',
        3 => 'card',
        4 => 'identity',
        5 => 'sshKey',
        6 => 'bankAccount',
        7 => 'driversLicense',
        8 => 'passport',
    ];

    protected $table = 'ciphers';

    protected $primaryKey = 'uuid';

    protected function casts(): array
    {
        return [
            'created_at' => NaiveDateTime::class,
            'updated_at' => NaiveDateTime::class,
            'deleted_at' => NaiveDateTime::class,
            'atype' => 'integer',
            'reprompt' => 'integer',
        ];
    }

    public static function new(int $atype, string $name): self
    {
        $now = Dates::now();

        return new self([
            'uuid' => Random::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
            'user_uuid' => null,
            'organization_uuid' => null,
            'atype' => $atype,
            'name' => $name,
            'notes' => null,
            'fields' => null,
            'data' => '',
            'password_history' => null,
            'deleted_at' => null,
            'reprompt' => null,
            'key' => null,
        ]);
    }

    /**
     * Cipher::to_json. A user's view (CipherSyncType::User) carries the folder,
     * favorite, archive and access flags; an organization's view (as admins
     * list the organization's ciphers) leaves them out.
     *
     * @param  array{folders?: array<string, string>, favorites?: list<string>, archives?: array<string, DateTimeImmutable>, members?: array<string, Membership>, collections?: array<string, list<string>>, userCollections?: array<string, CollectionUser>}|null  $sync
     *                                                                                                                                                                                                                                                                     Data gathered once for many ciphers, as in CipherSyncData
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    public function toApi(string $host, string $userUuid, ?array $sync = null, bool $organizationView = false): array
    {
        $key = self::TYPE_KEYS[$this->atype] ?? throw ApiError::message("Cipher {$this->uuid} has an invalid type {$this->atype}");

        $attachments = Attachment::findByCipher($this->uuid);

        $json = [
            'object' => 'cipherDetails',
            'id' => $this->uuid,
            'type' => $this->atype,
            'creationDate' => Dates::format($this->created_at),
            'revisionDate' => Dates::format($this->updated_at),
            'deletedDate' => $this->deleted_at === null ? null : Dates::format($this->deleted_at),
            'reprompt' => in_array($this->reprompt, [0, 1], true) ? $this->reprompt : 0,
            'organizationId' => $this->organization_uuid,
            'key' => $this->key,
            'attachments' => $attachments->isEmpty() ? null : $attachments->map(static fn (Attachment $attachment): array => $attachment->toApi($host))->all(),
            // UseTotp is on by default for organizations; together with premium it shows the TOTP counter.
            'organizationUseTotp' => true,
            'collectionIds' => $sync === null ? $this->adminCollections($userUuid) : ($sync['collections'][$this->uuid] ?? []),
            'name' => $this->name,
            'notes' => $this->notes,
            'fields' => $this->fieldsJson(),
            'passwordHistory' => $this->passwordHistoryJson(),
            // Every type is listed as null; only the cipher's own is filled in below.
            'login' => null,
            'secureNote' => null,
            'card' => null,
            'identity' => null,
            'sshKey' => null,
            'bankAccount' => null,
            'driversLicense' => null,
            'passport' => null,
        ];

        if (! $organizationView) {
            [$readOnly, $hidePasswords] = $this->accessRestrictions($userUuid, $sync) ?? [true, true, false];
            $archivedAt = $sync === null ? Archive::archivedAt($this->uuid, $userUuid) : ($sync['archives'][$this->uuid] ?? null);

            $json['folderId'] = $sync === null ? $this->folderUuid($userUuid) : ($sync['folders'][$this->uuid] ?? null);
            $json['favorite'] = $sync === null ? Favorite::isFavorite($this->uuid, $userUuid) : in_array($this->uuid, $sync['favorites'] ?? [], true);
            $json['archivedDate'] = $archivedAt === null ? null : Dates::format($archivedAt);
            // False when an organization restricts the user's collection.
            $json['edit'] = ! $readOnly;
            $json['viewPassword'] = ! $hidePasswords;
            // The newer form, read by clients since v2025.6.0.
            $json['permissions'] = ['delete' => ! $readOnly, 'restore' => ! $readOnly];
        }
        $json[$key] = $this->typeDataJson();

        return Json::sorted($json);
    }

    /**
     * Cipher::get_access_restrictions: (read only, hide passwords, manage), or
     * null without access. Own ciphers and those of organizations where the
     * user has full access have none. Across several collections a flag holds
     * only if all of them set it; manage if any does. Groups are not ported yet.
     *
     * @param  array{members?: array<string, Membership>, collections?: array<string, list<string>>, userCollections?: array<string, CollectionUser>}|null  $sync
     * @return array{bool, bool, bool}|null
     */
    public function accessRestrictions(string $userUuid, ?array $sync = null): ?array
    {
        if ($this->user_uuid !== null && $this->user_uuid === $userUuid) {
            return [false, false, true];
        }

        if ($this->organization_uuid !== null) {
            $member = $sync === null
                ? Membership::findConfirmedByUserAndOrg($userUuid, $this->organization_uuid)
                : ($sync['members'][$this->organization_uuid] ?? null);
            if ($member?->hasFullAccess() === true) {
                return [false, false, true];
            }
        }

        if ($sync === null) {
            $rows = $this->userCollectionsAccessFlags($userUuid);
        } else {
            $rows = [];
            foreach ($sync['collections'][$this->uuid] ?? [] as $collectionUuid) {
                $access = $sync['userCollections'][$collectionUuid] ?? null;
                if ($access !== null) {
                    $rows[] = [$access->readOnly, $access->hidePasswords, $access->manage];
                }
            }
        }
        if ($rows === []) {
            return null;
        }

        [$readOnly, $hidePasswords, $manage] = [true, true, false];
        foreach ($rows as [$rowReadOnly, $rowHidePasswords, $rowManage]) {
            $readOnly = $readOnly && $rowReadOnly;
            $hidePasswords = $hidePasswords && $rowHidePasswords;
            $manage = $manage || $rowManage;
        }

        return [$readOnly, $hidePasswords, $manage];
    }

    /**
     * The flags of the collections of the cipher the user reaches through a
     * confirmed membership.
     *
     * @return list<array{bool, bool, bool}>
     */
    private function userCollectionsAccessFlags(string $userUuid): array
    {
        $rows = DB::table('ciphers')
            ->join('ciphers_collections', 'ciphers.uuid', '=', 'ciphers_collections.cipher_uuid')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('ciphers.organization_uuid', '=', 'users_organizations.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid)
                    ->where('users_organizations.status', '=', Membership::STATUS_CONFIRMED);
            })
            ->join('users_collections', function (JoinClause $join): void {
                $join->on('ciphers_collections.collection_uuid', '=', 'users_collections.collection_uuid')
                    ->on('users_organizations.user_uuid', '=', 'users_collections.user_uuid');
            })
            ->where('ciphers.uuid', $this->uuid)
            ->select('users_collections.read_only', 'users_collections.hide_passwords', 'users_collections.manage')
            ->get();

        $flags = [];
        foreach ($rows as $row) {
            $flags[] = [(bool) $row->read_only, (bool) $row->hide_passwords, (bool) $row->manage];
        }

        return $flags;
    }

    /**
     * Cipher::get_admin_collections: the cipher's collections the user can
     * write to, or all of them for owners, admins and members with access to all.
     *
     * @return list<string>
     */
    public function adminCollections(string $userUuid): array
    {
        $ids = DB::table('ciphers_collections')
            ->join('collections', 'collections.uuid', '=', 'ciphers_collections.collection_uuid')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'collections.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->leftJoin('users_collections', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_collections.collection_uuid', '=', 'ciphers_collections.collection_uuid')
                    ->where('users_collections.user_uuid', '=', $userUuid);
            })
            ->where('ciphers_collections.cipher_uuid', $this->uuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->where(static fn (QueryBuilder $query) => $query->where('users_organizations.access_all', true)
                ->orWhere(static fn (QueryBuilder $query) => $query->where('users_collections.user_uuid', $userUuid)->where('users_collections.read_only', false))
                ->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN))
            ->pluck('ciphers_collections.collection_uuid');

        return array_values(array_filter($ids->all(), is_string(...)));
    }

    /**
     * Cipher::get_collections: the cipher's collections the user can write
     * to, or all of them with access to all.
     *
     * @return list<string>
     */
    public function collectionsOfUser(string $userUuid): array
    {
        $ids = DB::table('ciphers_collections')
            ->join('collections', 'collections.uuid', '=', 'ciphers_collections.collection_uuid')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'collections.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->leftJoin('users_collections', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_collections.collection_uuid', '=', 'ciphers_collections.collection_uuid')
                    ->where('users_collections.user_uuid', '=', $userUuid);
            })
            ->where('ciphers_collections.cipher_uuid', $this->uuid)
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->where(static fn (QueryBuilder $query) => $query->where('users_organizations.access_all', true)
                ->orWhere(static fn (QueryBuilder $query) => $query->where('users_collections.user_uuid', $userUuid)->where('users_collections.read_only', false)))
            ->pluck('ciphers_collections.collection_uuid');

        return array_values(array_filter($ids->all(), is_string(...)));
    }

    /**
     * Cipher::get_collections_with_cipher_by_user: for a sync, the collections
     * of every cipher the user reaches.
     *
     * @return array<string, list<string>> Collection ids by cipher
     */
    public static function collectionsWithCipherByUser(string $userUuid): array
    {
        $rows = DB::table('ciphers_collections')
            ->join('collections', 'collections.uuid', '=', 'ciphers_collections.collection_uuid')
            ->join('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_organizations.org_uuid', '=', 'collections.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid);
            })
            ->leftJoin('users_collections', function (JoinClause $join) use ($userUuid): void {
                $join->on('users_collections.collection_uuid', '=', 'ciphers_collections.collection_uuid')
                    ->where('users_collections.user_uuid', '=', $userUuid);
            })
            ->where(static fn (QueryBuilder $query) => $query->where('users_collections.user_uuid', $userUuid)
                ->orWhere('users_organizations.access_all', true)
                ->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN))
            ->where('users_organizations.status', Membership::STATUS_CONFIRMED)
            ->select('ciphers_collections.cipher_uuid', 'ciphers_collections.collection_uuid')
            ->distinct()
            ->get();

        $collections = [];
        foreach ($rows as $row) {
            if (is_string($row->cipher_uuid) && is_string($row->collection_uuid)) {
                $collections[$row->cipher_uuid][] = $row->collection_uuid;
            }
        }

        return $collections;
    }

    public function isAccessibleToUser(string $userUuid): bool
    {
        return $this->accessRestrictions($userUuid) !== null;
    }

    public function isWriteAccessibleToUser(string $userUuid): bool
    {
        $restrictions = $this->accessRestrictions($userUuid);

        return $restrictions !== null && (! $restrictions[0] || $restrictions[2]);
    }

    /**
     * Whether the user may change the cipher's collections: write access with
     * visible passwords, or manage, so that nobody escalates their rights.
     */
    public function isInEditableCollectionByUser(string $userUuid): bool
    {
        $restrictions = $this->accessRestrictions($userUuid);

        return $restrictions !== null && ((! $restrictions[0] && ! $restrictions[1]) || $restrictions[2]);
    }

    /**
     * Saving tells the owner's clients to sync.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ApiError
     */
    public function save(array $options = []): bool
    {
        $this->updateUsersRevision();
        $this->updated_at = Dates::now();
        $this->upsertRow(['uuid'], 'Error saving cipher');

        return true;
    }

    /**
     * Delete for good, with its folder links, attachments and favorites.
     *
     * @throws ApiError
     */
    public function deleteCipher(): void
    {
        $this->updateUsersRevision();
        FolderCipher::deleteAllByCipher($this->uuid);
        CollectionCipher::deleteAllByCipher($this->uuid);
        Attachment::deleteAllByCipher($this->uuid);
        Favorite::deleteAllByCipher($this->uuid);

        try {
            self::query()->where('uuid', $this->uuid)->delete();
        } catch (QueryException) {
            throw ApiError::message('Error deleting cipher');
        }
    }

    /**
     * @return list<string> The users whose clients must sync
     */
    public function updateUsersRevision(): array
    {
        if ($this->user_uuid !== null) {
            User::updateUuidRevision($this->user_uuid);

            return [$this->user_uuid];
        }
        if ($this->organization_uuid === null) {
            return [];
        }

        // Everyone in the organization who reaches the cipher.
        $users = [];
        foreach (Membership::findByCipherAndOrg($this->uuid, $this->organization_uuid) as $member) {
            User::updateUuidRevision($member->user_uuid);
            $users[] = $member->user_uuid;
        }

        return $users;
    }

    /**
     * Move the cipher into one of the user's folders, or out of them.
     *
     * @throws ApiError
     */
    public function moveToFolder(?string $folderUuid, string $userUuid): void
    {
        User::updateUuidRevision($userUuid);
        $current = $this->folderUuid($userUuid);

        if ($current === $folderUuid) {
            return;
        }
        if ($current !== null) {
            if (! FolderCipher::exists($current, $this->uuid)) {
                if ($folderUuid === null) {
                    throw ApiError::message("Couldn't move from previous folder");
                }
            } else {
                FolderCipher::remove($current, $this->uuid);
            }
        }
        if ($folderUuid !== null) {
            FolderCipher::add($folderUuid, $this->uuid);
        }
    }

    /**
     * @throws ApiError
     */
    public function setFavorite(?bool $favorite, string $userUuid): void
    {
        if ($favorite !== null) {
            Favorite::setFavorite($favorite, $this->uuid, $userUuid);
        }
    }

    public function folderUuid(string $userUuid): ?string
    {
        $folder = FolderCipher::query()
            ->join('folders', 'folders.uuid', '=', 'folders_ciphers.folder_uuid')
            ->where('folders.user_uuid', $userUuid)
            ->where('folders_ciphers.cipher_uuid', $this->uuid)
            ->value('folders_ciphers.folder_uuid');

        return is_string($folder) ? $folder : null;
    }

    public static function findByUuid(string $uuid): ?self
    {
        return self::query()->where('uuid', $uuid)->first();
    }

    /**
     * Cipher::find_by_user: own ciphers, those of organizations where the user
     * has access to all, and those of collections the user reaches through a
     * confirmed membership. Admins and owners also get the rest of their
     * organizations' ciphers unless only visible ones are asked for.
     *
     * @param  list<string>  $uuids  Only these, when given
     * @return Collection<int, self>
     */
    public static function findByUser(string $userUuid, bool $visibleOnly, array $uuids = []): Collection
    {
        $query = self::query()
            ->select('ciphers.*')
            ->leftJoin('ciphers_collections', 'ciphers.uuid', '=', 'ciphers_collections.cipher_uuid')
            ->leftJoin('users_organizations', function (JoinClause $join) use ($userUuid): void {
                $join->on('ciphers.organization_uuid', '=', 'users_organizations.org_uuid')
                    ->where('users_organizations.user_uuid', '=', $userUuid)
                    ->where('users_organizations.status', '=', Membership::STATUS_CONFIRMED);
            })
            ->leftJoin('users_collections', function (JoinClause $join): void {
                $join->on('ciphers_collections.collection_uuid', '=', 'users_collections.collection_uuid')
                    // Unconfirmed members have no row above, so no access here either.
                    ->on('users_organizations.user_uuid', '=', 'users_collections.user_uuid');
            })
            ->where(static function ($query) use ($userUuid, $visibleOnly): void {
                $query->where('ciphers.user_uuid', $userUuid)
                    ->orWhere('users_organizations.access_all', true)
                    ->orWhere('users_collections.user_uuid', $userUuid);
                if (! $visibleOnly) {
                    $query->orWhere('users_organizations.atype', '<=', Membership::TYPE_ADMIN);
                }
            });
        if ($uuids !== []) {
            $query->whereIn('ciphers.uuid', $uuids);
        }

        return $query->distinct()->get();
    }

    /**
     * The ciphers a user sees.
     *
     * @param  list<string>  $uuids  Only these, when given
     * @return Collection<int, self>
     */
    public static function findByUserVisible(string $userUuid, array $uuids = []): Collection
    {
        return self::findByUser($userUuid, true, $uuids);
    }

    public static function findByUuidAndOrg(string $uuid, string $orgUuid): ?self
    {
        return self::query()->where('uuid', $uuid)->where('organization_uuid', $orgUuid)->first();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findByOrg(string $orgUuid): Collection
    {
        return self::query()->where('organization_uuid', $orgUuid)->get();
    }

    /**
     * @return Collection<int, self>
     */
    public static function findOwnedByUser(string $userUuid): Collection
    {
        return self::query()->where('user_uuid', $userUuid)->whereNull('organization_uuid')->get();
    }

    /**
     * The custom fields, keys in lower camel case, each with a numeric type
     * (hidden when unreadable, so nothing is disclosed by accident).
     *
     * @return list<mixed>
     */
    private function fieldsJson(): array
    {
        $fields = self::decodeList($this->fields);
        if ($fields === null) {
            return [];
        }

        foreach ($fields as $index => $field) {
            // Every item is an object; an empty one gets a type too.
            $field = is_array($field) ? $field : [];
            $type = $field['type'] ?? null;
            if (is_string($type)) {
                $field['type'] = preg_match('/^\+?[0-9]+$/D', $type) && (int) ltrim($type, '+') <= 255 ? (int) ltrim($type, '+') : 1;
            } elseif (! is_int($type) && ! is_float($type)) {
                $field['type'] = 1;
            }
            $fields[$index] = $field;
        }

        return $fields;
    }

    /**
     * Entries with a string password only, each with a valid lastUsedDate.
     *
     * @return list<mixed>
     */
    private function passwordHistoryJson(): array
    {
        $history = self::decodeList($this->password_history) ?? [];

        $result = [];
        foreach ($history as $entry) {
            if (! is_array($entry) || ! is_string($entry['password'] ?? null)) {
                continue;
            }
            $lastUsed = $entry['lastUsedDate'] ?? null;
            $entry['lastUsedDate'] = is_string($lastUsed) ? JsonValue::formatRfc3339($lastUsed) : '1970-01-01T00:00:00.000000Z';
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * The type-specific data, repaired where clients would choke on it.
     */
    private function typeDataJson(): mixed
    {
        [$ok, $decoded] = JsonValue::decode($this->data);
        // LowerCase<Value> reads objects only; anything else becomes an empty object.
        $data = $ok && $decoded instanceof stdClass ? JsonValue::lowercaseKeys($decoded) : new stdClass;

        if ($this->atype === 1) {
            $data = is_array($data) ? $data : [];
            // Upstream always has an uri: the first of the uris.
            $data['uri'] = null;
            // Upstream reads uris through a mutable index, which adds the key.
            $data['uris'] ??= null;
            if (is_array($data['uris']) && array_is_list($data['uris']) && $data['uris'] !== []) {
                $uris = $data['uris'];
                foreach ($uris as $index => $uri) {
                    $match = is_array($uri) ? ($uri['match'] ?? null) : null;
                    // A match is a number or null.
                    if (is_array($uri) && is_string($match)) {
                        $uri['match'] = preg_match('/^\+?[0-9]+$/D', $match) && (int) ltrim($match, '+') <= 255 ? (int) ltrim($match, '+') : null;
                        $uris[$index] = $uri;
                    }
                }
                $data['uris'] = $uris;
                $first = $uris[0] ?? null;
                $data['uri'] = is_array($first) ? ($first['uri'] ?? null) : null;
            }
            if (is_string($data['passwordRevisionDate'] ?? null)) {
                $data['passwordRevisionDate'] = JsonValue::formatRfc3339($data['passwordRevisionDate']);
            }
        }

        // A secure note needs a numeric type, or mobile clients break.
        if ($this->atype === 2 && ! (is_array($data) && ! array_is_list($data) && (is_int($data['type'] ?? null) || is_float($data['type'] ?? null)))) {
            $data = ['type' => 0];
        }

        // An SSH key without its three parts is sent as null.
        if ($this->atype === 5) {
            foreach (['keyFingerprint', 'privateKey', 'publicKey'] as $part) {
                if (! is_array($data) || ! is_string($data[$part] ?? null) || $data[$part] === '') {
                    return null;
                }
            }
        }

        return $data;
    }

    /**
     * A stored JSON list of objects; null when it is anything else.
     *
     * @return list<mixed>|null
     */
    private static function decodeList(?string $json): ?array
    {
        if ($json === null) {
            return null;
        }

        [$ok, $decoded] = JsonValue::decode($json);
        if (! $ok || ! is_array($decoded)) {
            return null;
        }
        // Vec<LowerCase<Value>>: a list of objects, or nothing.
        foreach ($decoded as $item) {
            if (! $item instanceof stdClass) {
                return null;
            }
        }

        $list = JsonValue::lowercaseKeys($decoded);

        return is_array($list) ? array_values($list) : null;
    }
}
