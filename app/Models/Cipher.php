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
use Illuminate\Database\QueryException;
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
     * Cipher::to_json for a user's own view (CipherSyncType::User).
     *
     * @param  array{folders?: array<string, string>, favorites?: list<string>, archives?: array<string, DateTimeImmutable>}|null  $sync
     *                                                                                                                                    Data gathered once for many ciphers, as in CipherSyncData
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    public function toApi(string $host, string $userUuid, ?array $sync = null): array
    {
        $key = self::TYPE_KEYS[$this->atype] ?? throw ApiError::message("Cipher {$this->uuid} has an invalid type {$this->atype}");

        $attachments = Attachment::findByCipher($this->uuid);
        [$readOnly, $hidePasswords] = $this->accessRestrictions($userUuid) ?? [true, true, false];

        $archivedAt = $sync === null ? Archive::archivedAt($this->uuid, $userUuid) : ($sync['archives'][$this->uuid] ?? null);

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
            'collectionIds' => [],
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
            'folderId' => $sync === null ? $this->folderUuid($userUuid) : ($sync['folders'][$this->uuid] ?? null),
            'favorite' => $sync === null ? Favorite::isFavorite($this->uuid, $userUuid) : in_array($this->uuid, $sync['favorites'] ?? [], true),
            'archivedDate' => $archivedAt === null ? null : Dates::format($archivedAt),
            // False when an organization restricts the user's collection.
            'edit' => ! $readOnly,
            'viewPassword' => ! $hidePasswords,
            // The newer form, read by clients since v2025.6.0.
            'permissions' => ['delete' => ! $readOnly, 'restore' => ! $readOnly],
        ];
        $json[$key] = $this->typeDataJson();

        return Json::sorted($json);
    }

    /**
     * The user's restrictions (read only, hide passwords, manage), or null
     * without access. Own ciphers have none.
     *
     * @return array{bool, bool, bool}|null
     *
     * @throws ApiError For organization ciphers of members, until organizations are ported
     */
    public function accessRestrictions(string $userUuid): ?array
    {
        if ($this->user_uuid !== null && $this->user_uuid === $userUuid) {
            return [false, false, true];
        }

        if ($this->organization_uuid !== null && Membership::query()->where('user_uuid', $userUuid)
            ->where('org_uuid', $this->organization_uuid)->where('status', Membership::STATUS_CONFIRMED)->exists()) {
            throw ApiError::message('Organizations are not implemented yet');
        }

        return null;
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
        if ($this->organization_uuid !== null) {
            throw ApiError::message('Organizations are not implemented yet');
        }
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

        return [];
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
     * The ciphers a user sees: own ones, and (once ported) those of organizations.
     *
     * @param  list<string>  $uuids  Only these, when given
     * @return Collection<int, self>
     *
     * @throws ApiError For confirmed members of organizations, until organizations are ported
     */
    public static function findByUserVisible(string $userUuid, array $uuids = []): Collection
    {
        if (Membership::query()->where('user_uuid', $userUuid)->where('status', Membership::STATUS_CONFIRMED)->exists()) {
            throw ApiError::message('Organizations are not implemented yet');
        }

        $query = self::query()->where('user_uuid', $userUuid);
        if ($uuids !== []) {
            $query->whereIn('uuid', $uuids);
        }

        return $query->get();
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
