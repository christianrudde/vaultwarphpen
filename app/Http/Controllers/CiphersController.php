<?php

namespace App\Http\Controllers;

use App\Auth\Headers;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\CipherData;
use App\Http\Json;
use App\Models\Archive;
use App\Models\Attachment;
use App\Models\Cipher;
use App\Models\Favorite;
use App\Models\Folder;
use App\Models\FolderCipher;
use App\Models\Membership;
use App\Models\OrgPolicy;
use App\Support\Dates;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * src/api/core/ciphers.rs; so far the personal vault. Live notifications to
 * other devices are not ported yet; clients see changes at their next sync.
 */
final readonly class CiphersController
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * GET /api/ciphers
     */
    public function getCiphers(Headers $headers): Response
    {
        return Json::response(Json::sorted([
            'data' => $this->ciphersJson($headers),
            'object' => 'list',
            'continuationToken' => null,
        ]));
    }

    /**
     * GET /api/ciphers/{cipher_id}, also as /admin and /details.
     */
    public function getCipher(Headers $headers, Request $request, string $cipherId): Response
    {
        $cipher = Cipher::findByUuid(Uuid::parameter($request, $cipherId)) ?? throw ApiError::message("Cipher doesn't exist");

        if (! $cipher->isAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not owned by user');
        }

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * POST /api/ciphers
     */
    public function postCiphers(Headers $headers, Request $request): Response
    {
        $data = CipherData::fromFields(Body::json($request));

        if ($data->encryptedFor !== $headers->user->uuid) {
            throw ApiError::message('Invalid user cipher', 422);
        }
        if ($data->encryptedByKeyId !== null && $headers->user->key_id !== null && $data->encryptedByKeyId !== $headers->user->key_id) {
            throw ApiError::message('Invalid key cipher', 422);
        }

        // Mobile clients send 0001-01-01T00:00:00 here; a new cipher has no revision to compare.
        $data->lastKnownRevisionDate = null;

        $cipher = Cipher::new($data->type, $data->name);
        $this->updateCipherFromData($cipher, $data, $headers, checkRevision: true);

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * PUT /api/ciphers/{cipher_id}, also POST and the /admin variants.
     */
    public function putCipher(Headers $headers, Request $request, string $cipherId): Response
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $data = CipherData::fromFields(Body::json($request));

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not write accessible');
        }

        $this->updateCipherFromData($cipher, $data, $headers, checkRevision: true);

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * PUT /api/ciphers/{cipher_id}/partial, also POST: only the user's folder and
     * favorite, which also works for read-only ciphers.
     */
    public function putCipherPartial(Headers $headers, Request $request, string $cipherId): Response
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $body = Body::json($request);
        $folderId = $body->optionalNonEmptyString('folderId');
        $favorite = $body->bool('favorite');

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message('Cipher does not exist');
        if (! $cipher->isAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher does not exist', logMessage: 'Cipher is not accessible for the current user');
        }
        if ($folderId !== null && Folder::findByUuidAndUser($folderId, $headers->user->uuid) === null) {
            throw ApiError::message('Invalid folder', logMessage: 'Folder does not exist or belongs to another user');
        }

        $cipher->moveToFolder($folderId, $headers->user->uuid);
        $cipher->setFavorite($favorite, $headers->user->uuid);

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * The visible ciphers of the user, with the per-user data gathered once.
     *
     * @return list<array<string, mixed>>
     */
    public function ciphersJson(Headers $headers): array
    {
        $userUuid = $headers->user->uuid;
        $ciphers = Cipher::findByUserVisible($userUuid);
        $sync = [
            'folders' => FolderCipher::folderByCipherForUser($userUuid),
            'favorites' => Favorite::cipherUuidsOfUser($userUuid),
            'archives' => Archive::archivedAtByCipherForUser($userUuid),
        ];

        return array_values($ciphers->map(static fn (Cipher $cipher): array => $cipher->toApi($headers->host, $userUuid, $sync))->all());
    }

    /**
     * ciphers::update_cipher_from_data
     *
     * @param  bool  $checkRevision  Whether this is an update from a client, not an import
     *
     * @throws ApiError
     */
    public function updateCipherFromData(Cipher $cipher, CipherData $data, Headers $headers, bool $checkRevision): void
    {
        $this->enforcePersonalOwnershipPolicy($data, $headers);

        // A client must not overwrite a newer version with its stale copy.
        if ($checkRevision && $data->lastKnownRevisionDate !== null) {
            $known = Dates::parseNaive($data->lastKnownRevisionDate);
            if ($known === null) {
                logger()->warning("Error parsing LastKnownRevisionDate '{$data->lastKnownRevisionDate}'");
            } else {
                $difference = ((int) $cipher->updated_at->format('Uu')) - ((int) $known->format('Uu'));
                if (intdiv($difference, 1_000_000) > 1) {
                    throw ApiError::message('The client copy of this cipher is out of date. Resync the client and try again.');
                }
            }
        }

        if ($cipher->organization_uuid !== null && $cipher->organization_uuid !== $data->organizationId) {
            throw ApiError::message('Organization mismatch. Please resync the client before updating the cipher');
        }

        $maxNoteSize = $this->config->int('_max_note_size');
        if ($data->notes !== null && strlen($data->notes) > $maxNoteSize) {
            throw ApiError::message("The field Notes exceeds the maximum encrypted value length of {$maxNoteSize} characters.");
        }

        if ($data->organizationId !== null) {
            $member = Membership::query()->where('user_uuid', $headers->user->uuid)->where('org_uuid', $data->organizationId)
                ->where('status', Membership::STATUS_CONFIRMED)->first();
            throw $member === null
                ? ApiError::message("You don't have permission to add item to organization")
                : ApiError::message('Organizations are not implemented yet');
        }
        $cipher->user_uuid = $headers->user->uuid;

        if ($data->folderId !== null && Folder::findByUuidAndUser($data->folderId, $headers->user->uuid) === null) {
            throw ApiError::message('Invalid folder', logMessage: 'Folder does not exist or belongs to another user');
        }

        // Rotating keys renames attachments and gives them new keys.
        foreach ($data->attachments2 ?? [] as $id => $attachment) {
            $saved = Attachment::query()->where('id', $id)->first();
            if ($saved === null) {
                // Removed by another client; upstream ignores it too.
                logger()->warning("Attachment {$id} doesn't exist");

                continue;
            }
            if ($saved->cipher_uuid !== $cipher->uuid) {
                // Clones carry the attachment data without the files.
                logger()->warning('Attachment is not owned by the cipher');

                break;
            }
            $saved->akey = $attachment['key'];
            $saved->file_name = $attachment['fileName'];
            $saved->save();
        }

        $typeKey = Cipher::TYPE_KEYS[$data->type] ?? throw ApiError::message('Invalid type');
        if (! array_key_exists($typeKey, $data->typeData)) {
            throw ApiError::message('Data missing');
        }
        $typeData = $data->typeData[$typeKey];
        // Javascript clients add a 'response' key that has no place in the stored data.
        if (is_array($typeData) && ! array_is_list($typeData)) {
            unset($typeData['response']);
            if (is_array($typeData['uris'] ?? null) && array_is_list($typeData['uris'])) {
                $typeData['uris'] = self::withoutResponse($typeData['uris']);
            }
        }

        $cipher->key = $data->key;
        $cipher->name = $data->name;
        $cipher->notes = $data->notes;
        $cipher->fields = $data->fields === null ? null : self::storedJson(self::withoutResponse($data->fields));
        $cipher->data = self::storedJson($typeData);
        $cipher->password_history = $data->passwordHistory === null ? null : self::storedJson($data->passwordHistory);
        $cipher->reprompt = in_array($data->reprompt, [0, 1], true) ? $data->reprompt : null;

        $cipher->save();
        $cipher->moveToFolder($data->folderId, $headers->user->uuid);
        $cipher->setFavorite($data->favorite, $headers->user->uuid);

        if ($data->archivedDate === null) {
            Archive::deleteByCipher($headers->user->uuid, $cipher->uuid);
        } else {
            $archivedAt = Dates::parseNaive($data->archivedDate);
            if ($archivedAt === null) {
                logger()->warning("Error parsing ArchivedDate '{$data->archivedDate}'");
            } else {
                Archive::store($headers->user->uuid, $cipher->uuid, $archivedAt);
            }
        }
    }

    /**
     * Members of an organization with the personal ownership policy may not
     * save items to their personal vault.
     *
     * @throws ApiError
     */
    private function enforcePersonalOwnershipPolicy(?CipherData $data, Headers $headers): void
    {
        if (($data === null || $data->organizationId === null)
            && OrgPolicy::isApplicableToUser($headers->user->uuid, OrgPolicy::TYPE_PERSONAL_OWNERSHIP)) {
            throw ApiError::message('Due to an Enterprise Policy, you are restricted from saving items to your personal vault.');
        }
    }

    /**
     * Drop the 'response' key of every object in a list.
     */
    private static function withoutResponse(mixed $value): mixed
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return $value;
        }

        return array_map(static function (mixed $item): mixed {
            if (is_array($item) && ! array_is_list($item)) {
                unset($item['response']);
            }

            return $item;
        }, $value);
    }

    /**
     * serde_json's to_string of a Value: compact, keys sorted.
     */
    private static function storedJson(mixed $value): string
    {
        return Json::encode(Json::sorted($value));
    }
}
