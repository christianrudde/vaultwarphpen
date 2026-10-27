<?php

namespace App\Http\Controllers;

use App\Auth\ClientVersion;
use App\Auth\Headers;
use App\Config\Config;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\Data\CipherData;
use App\Http\Data\Fields;
use App\Http\Data\NumberOrString;
use App\Http\Data\PasswordOrOtpData;
use App\Http\Data\Query;
use App\Http\Data\UploadData;
use App\Http\EmptyResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Http\Unauthorized;
use App\Models\Archive;
use App\Models\Attachment;
use App\Models\Cipher;
use App\Models\Favorite;
use App\Models\Folder;
use App\Models\FolderCipher;
use App\Models\Membership;
use App\Models\OrgPolicy;
use App\Models\Send;
use App\Support\CheckedInt;
use App\Support\Dates;
use App\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * src/api/core/ciphers.rs; so far the personal vault. Live notifications to
 * other devices are not ported yet; clients see changes at their next sync.
 */
final readonly class CiphersController
{
    /**
     * sends::SIZE_525_MB, the largest upload.
     */
    public const int SIZE_525_MB = 550_502_400;

    private const int ATTACHMENT_SIZE_LEEWAY = 1024 * 1024;

    public function __construct(
        private Config $config,
    ) {}

    /**
     * GET /api/sync: everything a client keeps of the vault.
     */
    public function sync(Headers $headers, Request $request): Response
    {
        $user = $headers->user;
        $clientVersion = ClientVersion::fromRequest($request);
        $excludeDomains = self::excludeDomains($request);

        $profile = $user->toApi();

        // Clients before 2024.12.0 cannot handle SSH keys.
        $showSshKeys = $clientVersion?->isAtLeast(2024, 12, 0) ?? false;
        $ciphers = array_values(array_filter(
            $this->ciphersJson($headers),
            static fn (array $cipher): bool => $showSshKeys || $cipher['type'] !== 5,
        ));

        $sends = Send::findByUser($user->uuid)->map(static fn (Send $send): array => $send->toApi())->all();
        $folders = Folder::findByUser($user->uuid)->map(static fn (Folder $folder): array => $folder->toApi())->all();
        $masterPasswordUnlock = $user->password_hash === '' ? null : [
            'kdf' => [
                'kdfType' => $user->client_kdf_type,
                'iterations' => $user->client_kdf_iter,
                'memory' => $user->client_kdf_memory,
                'parallelism' => $user->client_kdf_parallelism,
            ],
            // Named inconsistently; the apps move to the "wrapped" variant.
            'masterKeyEncryptedUserKey' => $user->akey,
            'masterKeyWrappedUserKey' => $user->akey,
            'salt' => $user->email,
        ];

        return Json::response(Json::sorted([
            'profile' => $profile,
            'folders' => array_values($folders),
            // Collections and policies come with organizations, which the profile already refuses.
            'collections' => [],
            'policies' => [],
            'policiesNew' => [],
            'ciphers' => $ciphers,
            'domains' => $excludeDomains ? null : CoreController::eqDomains($user, noExcluded: true),
            'sends' => array_values($sends),
            'userDecryption' => [
                'masterPasswordUnlock' => $masterPasswordUnlock,
                'userKeyId' => $user->key_id,
            ],
            'object' => 'sync',
        ]));
    }

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
     * POST /api/ciphers/create, also POST /api/ciphers/admin: a new cipher of
     * the user or an organization, or a clone.
     */
    public function postCiphersCreate(Headers $headers, Request $request): Response
    {
        $body = Body::json($request);
        $data = CipherData::fromFields($body->object('cipher', 'Cipher'));
        $collectionIds = $body->stringList('collectionIds', 'CollectionIds');

        if ($data->encryptedFor !== $headers->user->uuid) {
            throw ApiError::message('Invalid user cipher', 422);
        }
        // Checked here too, or an empty cipher would be saved below.
        $this->enforcePersonalOwnershipPolicy($data, $headers);

        $cipher = Cipher::new($data->type, $data->name);
        $cipher->user_uuid = $headers->user->uuid;
        $cipher->save();

        // Clones carry the revision of the original; a new cipher has none to compare.
        $data->lastKnownRevisionDate = null;

        try {
            return Json::response($this->shareCipherByUuid($cipher->uuid, $data, $collectionIds, $headers));
        } catch (ApiError|QueryException $error) {
            $cipher->deleteCipher();

            throw $error;
        }
    }

    /**
     * POST /api/ciphers/import: all items or none pass the validation; the
     * folders are created first, then the ciphers one by one.
     */
    public function postCiphersImport(Headers $headers, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $ciphers = array_map(CipherData::fromFields(...), $body->objectList('ciphers'));
        $folders = array_map(
            static fn (Fields $folder): array => [$folder->string('name'), $folder->optionalNonEmptyString('id')],
            $body->objectList('folders'),
        );
        $relations = array_map(
            static fn (Fields $relation): array => [$relation->unsigned('key'), $relation->unsigned('value')],
            $body->objectList('folderRelationships'),
        );

        $this->enforcePersonalOwnershipPolicy(null, $headers);
        $this->validateCipherData($ciphers);

        $userUuid = $headers->user->uuid;
        $existing = Folder::findByUser($userUuid)->pluck('uuid')->all();
        $folderIds = [];
        foreach ($folders as [$name, $id]) {
            if ($id !== null && in_array($id, $existing, true)) {
                $folderIds[] = $id;
            } else {
                $folder = Folder::new($userUuid, $name);
                $folder->save();
                $folderIds[] = $folder->uuid;
            }
        }

        // A cipher is in one folder at most; the last relation wins.
        $folderOfCipher = [];
        foreach ($relations as [$cipherIndex, $folderIndex]) {
            $folderOfCipher[$cipherIndex] = $folderIndex;
        }

        foreach ($ciphers as $index => $data) {
            $data->folderId = isset($folderOfCipher[$index]) ? ($folderIds[$folderOfCipher[$index]] ?? null) : null;
            $cipher = Cipher::new($data->type, $data->name);
            $this->updateCipherFromData($cipher, $data, $headers, checkRevision: false);
        }

        $headers->user->updateRevision();

        return new EmptyResponse;
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
     * GET /api/ciphers/{cipher_id}/attachment/{attachment_id}: the attachment
     * with a fresh download URL.
     */
    public function getAttachment(Headers $headers, Request $request, string $cipherId, string $attachmentId): Response
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $attachmentId = Uuid::idParameter($request, $attachmentId);

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not accessible');
        }

        $attachment = Attachment::findById($attachmentId) ?? throw ApiError::message("Attachment doesn't exist");
        if ($attachment->cipher_uuid !== $cipherId) {
            throw ApiError::message("Attachment doesn't belong to cipher");
        }

        return Json::response($attachment->toApi($headers->host));
    }

    /**
     * POST /api/ciphers/{cipher_id}/attachment/v2: the record of a new
     * attachment, and where to upload its data.
     */
    public function postAttachmentV2(Headers $headers, Request $request, string $cipherId): Response
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $body = Body::json($request);
        $key = $body->string('key');
        $fileName = $body->string('fileName');
        $fileSize = NumberOrString::optional($body, 'fileSize') ?? throw new HttpStatus(422, 'Missing field `fileSize`.');
        $adminRequest = $body->optionalBool('adminRequest');

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not write accessible');
        }

        $size = NumberOrString::intoI64($fileSize);
        if ($size < 0) {
            throw ApiError::message("Attachment size can't be negative");
        }
        // The upload limit; nothing larger arrives.
        if ($size > self::SIZE_525_MB) {
            throw ApiError::message('Max file size is 525 MB.');
        }

        $attachmentId = Attachment::generateId();
        Attachment::new($attachmentId, $cipher->uuid, $fileName, $size, $key)->save();

        return Json::response(Json::sorted([
            'object' => 'attachment-fileUpload',
            'attachmentId' => $attachmentId,
            'url' => "/ciphers/{$cipher->uuid}/attachment/{$attachmentId}",
            // FileUploadType::Direct: to this server, not to Azure.
            'fileUploadType' => 0,
            ($adminRequest === true ? 'cipherMiniResponse' : 'cipherResponse') => $cipher->toApi($headers->host, $headers->user->uuid),
        ]));
    }

    /**
     * POST /api/ciphers/{cipher_id}/attachment/{attachment_id}: the data of
     * an attachment created through the v2 API.
     */
    public function postAttachmentV2Data(Request $request, string $cipherId, string $attachmentId): EmptyResponse
    {
        UploadData::requireMultipart($request);
        $headers = app(Headers::class);
        $cipherId = Uuid::parameter($request, $cipherId);
        $attachmentId = Uuid::idParameter($request, $attachmentId);
        $data = UploadData::fromRequest($request);

        $attachment = Attachment::findById($attachmentId) ?? throw ApiError::message("Attachment doesn't exist");
        if ($attachment->cipher_uuid !== $cipherId) {
            throw ApiError::message("Attachment doesn't belong to cipher");
        }

        $this->saveAttachment($attachment, $cipherId, $data, $headers);

        return new EmptyResponse;
    }

    /**
     * POST /api/ciphers/{cipher_id}/attachment, also /attachment-admin: the
     * legacy upload, record and data at once.
     */
    public function postAttachment(Request $request, string $cipherId): Response
    {
        UploadData::requireMultipart($request);
        $headers = app(Headers::class);
        $cipherId = Uuid::parameter($request, $cipherId);
        $data = UploadData::fromRequest($request);

        $cipher = $this->saveAttachment(null, $cipherId, $data, $headers);

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * POST /api/ciphers/{cipher_id}/attachment/{attachment_id}/share: replace
     * an attachment by a new upload.
     */
    public function postAttachmentShare(Request $request, string $cipherId, string $attachmentId): Response
    {
        UploadData::requireMultipart($request);
        $headers = app(Headers::class);
        $cipherId = Uuid::parameter($request, $cipherId);
        $attachmentId = Uuid::idParameter($request, $attachmentId);
        $data = UploadData::fromRequest($request);

        $this->deleteAttachmentById($cipherId, $attachmentId, $headers);
        $cipher = $this->saveAttachment(null, $cipherId, $data, $headers);

        return Json::response($cipher->toApi($headers->host, $headers->user->uuid));
    }

    /**
     * DELETE /api/ciphers/{cipher_id}/attachment/{attachment_id}, also /admin,
     * and POST .../delete and .../delete-admin
     */
    public function deleteAttachment(Headers $headers, Request $request, string $cipherId, string $attachmentId): Response
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $attachmentId = Uuid::idParameter($request, $attachmentId);

        return Json::response($this->deleteAttachmentById($cipherId, $attachmentId, $headers));
    }

    /**
     * PUT /api/ciphers/{cipher_id}/delete, also /delete-admin: into the trash.
     */
    public function softDeleteCipher(Headers $headers, Request $request, string $cipherId): EmptyResponse
    {
        $this->deleteCipherByUuid(Uuid::parameter($request, $cipherId), $headers, soft: true, multi: false);

        return new EmptyResponse;
    }

    /**
     * DELETE /api/ciphers/{cipher_id}, also /admin, and POST .../delete and
     * .../delete-admin: for good.
     */
    public function deleteCipher(Headers $headers, Request $request, string $cipherId): EmptyResponse
    {
        $this->deleteCipherByUuid(Uuid::parameter($request, $cipherId), $headers, soft: false, multi: false);

        return new EmptyResponse;
    }

    /**
     * PUT /api/ciphers/delete, also /delete-admin: several into the trash.
     */
    public function softDeleteCiphers(Headers $headers, Request $request): EmptyResponse
    {
        foreach (self::cipherIds($request) as $cipherId) {
            $this->deleteCipherByUuid($cipherId, $headers, soft: true, multi: true);
        }

        return new EmptyResponse;
    }

    /**
     * DELETE /api/ciphers, also /admin, and POST /api/ciphers/delete and
     * /delete-admin: several for good.
     */
    public function deleteCiphers(Headers $headers, Request $request): EmptyResponse
    {
        foreach (self::cipherIds($request) as $cipherId) {
            $this->deleteCipherByUuid($cipherId, $headers, soft: false, multi: true);
        }

        return new EmptyResponse;
    }

    /**
     * PUT /api/ciphers/{cipher_id}/restore, also /restore-admin: out of the trash.
     */
    public function restoreCipher(Headers $headers, Request $request, string $cipherId): Response
    {
        return Json::response($this->restoreCipherByUuid(Uuid::parameter($request, $cipherId), $headers, multi: false));
    }

    /**
     * PUT /api/ciphers/restore, also /restore-admin
     */
    public function restoreCiphers(Headers $headers, Request $request): Response
    {
        $ciphers = [];
        foreach (self::cipherIds($request) as $cipherId) {
            $ciphers[] = $this->restoreCipherByUuid($cipherId, $headers, multi: true);
        }

        return Json::response(Json::sorted(['data' => $ciphers, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * POST /api/ciphers/move, also PUT
     */
    public function moveCiphers(Headers $headers, Request $request): EmptyResponse
    {
        $body = Body::json($request);
        $folderId = $body->optionalNonEmptyString('folderId');
        $ids = $body->stringList('ids');
        $userUuid = $headers->user->uuid;

        if ($folderId !== null && Folder::findByUuidAndUser($folderId, $userUuid) === null) {
            throw ApiError::message('Invalid folder', logMessage: 'Folder does not exist or belongs to another user');
        }

        // Ciphers the user cannot access are left out. Upstream's query takes
        // no ids as no filter, so an empty list moves every cipher.
        $accessible = Cipher::findByUserVisible($userUuid, $ids);
        foreach ($accessible as $cipher) {
            $cipher->moveToFolder($folderId, $userUuid);
        }

        if (count($ids) !== $accessible->count()) {
            throw ApiError::message("Not all ciphers are moved! {$accessible->count()} of the selected ".count($ids).' were moved.');
        }

        return new EmptyResponse;
    }

    /**
     * POST /api/ciphers/purge: empty the personal vault. With an
     * organizationId it is the organization's vault (purge_org_vault).
     */
    public function purgeVault(Headers $headers, Request $request): EmptyResponse
    {
        $organizationId = $request->query('organizationId');
        if (is_string($organizationId)) {
            // OwnerHeaders: only members get further, and organizations are not ported yet.
            if (! Uuid::isValid($organizationId)) {
                throw new Unauthorized('Error getting the organization id');
            }
            if (! Membership::query()->where('user_uuid', $headers->user->uuid)->where('org_uuid', $organizationId)->exists()) {
                throw new Unauthorized("The current user isn't member of the organization");
            }

            throw ApiError::message('Organizations are not implemented yet');
        }

        $data = PasswordOrOtpData::fromFields(Body::json($request));
        $user = $headers->user;
        $data->validate($user, true, $this->config);

        foreach (Cipher::findOwnedByUser($user->uuid) as $cipher) {
            $cipher->deleteCipher();
        }
        Folder::deleteAllByUser($user->uuid);
        $user->updateRevision();

        return new EmptyResponse;
    }

    /**
     * PUT /api/ciphers/{cipher_id}/archive
     */
    public function archiveCipher(Headers $headers, Request $request, string $cipherId): Response
    {
        return Json::response($this->archiveCipherByUuid(Uuid::parameter($request, $cipherId), $headers, archive: true, multi: false));
    }

    /**
     * PUT /api/ciphers/archive
     */
    public function archiveCiphers(Headers $headers, Request $request): Response
    {
        return $this->archiveMultiple($headers, $request, archive: true);
    }

    /**
     * PUT /api/ciphers/{cipher_id}/unarchive
     */
    public function unarchiveCipher(Headers $headers, Request $request, string $cipherId): Response
    {
        return Json::response($this->archiveCipherByUuid(Uuid::parameter($request, $cipherId), $headers, archive: false, multi: false));
    }

    /**
     * PUT /api/ciphers/unarchive
     */
    public function unarchiveCiphers(Headers $headers, Request $request): Response
    {
        return $this->archiveMultiple($headers, $request, archive: false);
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
     * ciphers::share_cipher_by_uuid: an update that may move the cipher into
     * collections of an organization.
     *
     * @param  list<string>  $collectionIds
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function shareCipherByUuid(string $cipherId, CipherData $data, array $collectionIds, Headers $headers): array
    {
        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not write accessible');
        }

        // Checked before the collections are linked; there is no transaction.
        if ($cipher->organization_uuid !== null && $cipher->organization_uuid !== $data->organizationId) {
            throw ApiError::message('Organization mismatch. Please resync the client before updating the cipher');
        }

        if ($data->organizationId !== null) {
            foreach ($collectionIds as $collectionId) {
                $exists = DB::table('collections')->where('uuid', $collectionId)->where('org_uuid', $data->organizationId)->exists();

                throw $exists ? ApiError::message('Organizations are not implemented yet') : ApiError::message('Invalid collection ID provided');
            }
        }

        $this->updateCipherFromData($cipher, $data, $headers, checkRevision: true);

        return $cipher->toApi($headers->host, $headers->user->uuid);
    }

    /**
     * Cipher::validate_cipher_data: the import's notes and password histories.
     *
     * @param  list<CipherData>  $ciphers
     *
     * @throws ApiError
     */
    private function validateCipherData(array $ciphers): void
    {
        $maxNoteSize = $this->config->int('_max_note_size');
        $errors = [];
        foreach ($ciphers as $index => $cipher) {
            if ($cipher->notes !== null && strlen($cipher->notes) > $maxNoteSize) {
                $errors["Ciphers[{$index}].Notes"] = ["The field Notes exceeds the maximum encrypted value length of {$maxNoteSize} characters."];
            }

            // Upstream files this one under Notes too.
            if (is_array($cipher->passwordHistory) && array_is_list($cipher->passwordHistory)) {
                foreach ($cipher->passwordHistory as $entry) {
                    if (is_array($entry) && ! array_is_list($entry) && array_key_exists('password', $entry) && ! is_string($entry['password'])) {
                        $errors["Ciphers[{$index}].Notes"] = ['The password history contains a `null` value. Only strings are allowed.'];

                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw ApiError::json(Json::sorted([
                'message' => 'The model state is invalid.',
                'validationErrors' => $errors,
                'object' => 'error',
            ]), 'Import validation errors');
        }
    }

    /**
     * ciphers::save_attachment: checks the storage limit and stores the data.
     * The v2 API passes the record it created; the legacy API creates it here.
     *
     * @throws ApiError
     */
    private function saveAttachment(?Attachment $attachment, string $cipherId, UploadData $data, Headers $headers): Cipher
    {
        $size = $data->size();

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not write accessible');
        }

        // The v2 record already counts towards the limit.
        $sizeAdjust = $attachment === null ? 0 : $attachment->file_size;
        $sizeLimit = match (true) {
            $cipher->user_uuid !== null => $this->attachmentSpaceLeft('user_attachment_limit', Attachment::sizeByUser($cipher->user_uuid), $sizeAdjust),
            $cipher->organization_uuid !== null => $this->attachmentSpaceLeft('org_attachment_limit', Attachment::sizeByOrg($cipher->organization_uuid), $sizeAdjust),
            default => throw ApiError::message('Cipher is neither owned by a user nor an organization'),
        };
        if ($sizeLimit !== null && $size > $sizeLimit) {
            throw ApiError::message('Attachment storage limit exceeded with this file');
        }

        $fileId = $attachment === null ? Attachment::generateId() : $attachment->id;

        if ($attachment !== null) {
            // The data may differ from the announced size by 1 MiB, like upstream allows.
            $maxSize = CheckedInt::add($attachment->file_size, self::ATTACHMENT_SIZE_LEEWAY) ?? throw ApiError::message('Invalid attachment size max');
            $minSize = CheckedInt::sub($attachment->file_size, self::ATTACHMENT_SIZE_LEEWAY) ?? throw ApiError::message('Invalid attachment size min');

            if ($minSize <= $size && $size <= $maxSize) {
                if ($size !== $attachment->file_size) {
                    $attachment->file_size = $size;
                    $attachment->save();
                }
            } else {
                try {
                    $attachment->deleteAttachment();
                } catch (ApiError) {
                    // Upstream ignores this too.
                }

                throw ApiError::message("Attachment size mismatch (expected within [{$minSize}, {$maxSize}], got {$size})");
            }
        } else {
            $fileName = $data->rawName() ?? throw ApiError::message('No filename provided');
            $key = $data->key ?? throw ApiError::message('No attachment key provided');
            Attachment::new($fileId, $cipherId, $fileName, $size, $key)->save();
        }

        $data->store(Attachment::path($cipherId, $fileId));
        $cipher->updateUsersRevision();

        return $cipher;
    }

    /**
     * The bytes left under a limit in kB; null without a limit.
     *
     * @throws ApiError
     */
    private function attachmentSpaceLeft(string $setting, int $used, int $sizeAdjust): ?int
    {
        $limit = $this->config->optionalInt($setting);
        if ($limit === 0) {
            throw ApiError::message('Attachments are disabled');
        }
        if ($limit === null) {
            return null;
        }

        $left = CheckedInt::mul($limit, 1024);
        $left = $left === null ? null : CheckedInt::sub($left, $used);
        $left = ($left === null ? null : CheckedInt::add($left, $sizeAdjust)) ?? throw ApiError::message('Attachment size overflow');
        if ($left <= 0) {
            throw ApiError::message('Attachment storage limit reached! Delete some attachments to free up space');
        }

        return $left;
    }

    /**
     * ciphers::delete_cipher_attachment_by_id
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function deleteAttachmentById(string $cipherId, string $attachmentId, Headers $headers): array
    {
        $attachment = Attachment::findById($attachmentId) ?? throw ApiError::message("Attachment doesn't exist");
        if ($attachment->cipher_uuid !== $cipherId) {
            throw ApiError::message('Attachment from other cipher');
        }

        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher cannot be deleted by user');
        }

        $attachment->deleteAttachment();
        $cipher->updateUsersRevision();

        return Json::sorted(['cipher' => $cipher->toApi($headers->host, $headers->user->uuid)]);
    }

    /**
     * ciphers::delete_cipher_by_uuid
     *
     * @throws ApiError
     */
    private function deleteCipherByUuid(string $cipherId, Headers $headers, bool $soft, bool $multi): void
    {
        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message("Cipher can't be deleted by user");
        }

        if ($soft) {
            $cipher->deleted_at = Dates::now();
            $cipher->save();
        } else {
            $cipher->deleteCipher();
        }
        if (! $multi) {
            $cipher->updateUsersRevision();
        }
    }

    /**
     * ciphers::restore_cipher_by_uuid
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function restoreCipherByUuid(string $cipherId, Headers $headers, bool $multi): array
    {
        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isWriteAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message("Cipher can't be restored by user");
        }

        $cipher->deleted_at = null;
        $cipher->save();
        if (! $multi) {
            $cipher->updateUsersRevision();
        }

        return $cipher->toApi($headers->host, $headers->user->uuid);
    }

    /**
     * ciphers::archive_cipher and unarchive_cipher: archiving is per user.
     *
     * @return array<string, mixed>
     *
     * @throws ApiError
     */
    private function archiveCipherByUuid(string $cipherId, Headers $headers, bool $archive, bool $multi): array
    {
        $cipher = Cipher::findByUuid($cipherId) ?? throw ApiError::message("Cipher doesn't exist");
        if (! $cipher->isAccessibleToUser($headers->user->uuid)) {
            throw ApiError::message('Cipher is not accessible for the current user');
        }

        if ($archive) {
            Archive::store($headers->user->uuid, $cipher->uuid, Dates::now());
        } else {
            Archive::deleteByCipher($headers->user->uuid, $cipher->uuid);
        }
        if (! $multi) {
            $cipher->updateUsersRevision();
        }

        return $cipher->toApi($headers->host, $headers->user->uuid);
    }

    /**
     * @throws ApiError
     */
    private function archiveMultiple(Headers $headers, Request $request, bool $archive): Response
    {
        $ciphers = [];
        foreach (self::cipherIds($request) as $cipherId) {
            $ciphers[] = $this->archiveCipherByUuid($cipherId, $headers, $archive, multi: true);
        }

        return Json::response(Json::sorted(['data' => $ciphers, 'object' => 'list', 'continuationToken' => null]));
    }

    /**
     * SyncData: the `excludeDomains` query field, a bool that defaults to
     * false; of repeated values the first counts. Rocket parses it after the
     * request guards; a bad value forwards the request to the web vault's
     * catch-all, which finds no such file.
     *
     * @throws HttpStatus
     */
    private static function excludeDomains(Request $request): bool
    {
        $value = Query::first($request, 'excludeDomains');

        return match ($value === null ? null : strtolower($value)) {
            null, 'off', 'false', 'no' => false,
            '', 'on', 'true', 'yes' => true,
            default => throw new HttpStatus(404, 'Query field is not a bool.'),
        };
    }

    /**
     * CipherIdsData: a HashSet, so each id once. Upstream's order is random.
     *
     * @return list<string>
     *
     * @throws HttpStatus
     */
    private static function cipherIds(Request $request): array
    {
        return array_values(array_unique(Body::json($request)->stringList('ids')));
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
