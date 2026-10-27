<?php

namespace App\Http\Controllers;

use App\Auth\Headers;
use App\Http\ApiError;
use App\Http\Data\Body;
use App\Http\EmptyResponse;
use App\Http\Json;
use App\Models\Folder;
use App\Support\Uuid;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * src/api/core/folders.rs. Live notifications to other devices are not
 * ported yet; clients pick changes up at their next sync.
 */
final readonly class FoldersController
{
    /**
     * GET /api/folders
     */
    public function getFolders(Headers $headers): Response
    {
        return Json::response(Json::sorted([
            'data' => Folder::findByUser($headers->user->uuid)->map(static fn (Folder $folder): array => $folder->toApi())->all(),
            'object' => 'list',
            'continuationToken' => null,
        ]));
    }

    /**
     * GET /api/folders/{folder_id}
     */
    public function getFolder(Headers $headers, Request $request, string $folderId): Response
    {
        return Json::response($this->folder($headers, $request, $folderId)->toApi());
    }

    /**
     * POST /api/folders
     */
    public function postFolders(Headers $headers, Request $request): Response
    {
        $name = self::folderData($request);

        $folder = Folder::new($headers->user->uuid, $name);
        $folder->save();

        return Json::response($folder->toApi());
    }

    /**
     * POST /api/folders/{folder_id}
     */
    public function postFolder(Headers $headers, Request $request, string $folderId): Response
    {
        return $this->putFolder($headers, $request, $folderId);
    }

    /**
     * PUT /api/folders/{folder_id}
     */
    public function putFolder(Headers $headers, Request $request, string $folderId): Response
    {
        $folderId = Uuid::parameter($request, $folderId);
        $name = self::folderData($request);

        $folder = $this->folder($headers, $request, $folderId);
        $folder->name = $name;
        $folder->save();

        return Json::response($folder->toApi());
    }

    /**
     * POST /api/folders/{folder_id}/delete
     */
    public function deleteFolderPost(Headers $headers, Request $request, string $folderId): EmptyResponse
    {
        return $this->deleteFolder($headers, $request, $folderId);
    }

    /**
     * DELETE /api/folders/{folder_id}
     */
    public function deleteFolder(Headers $headers, Request $request, string $folderId): EmptyResponse
    {
        $this->folder($headers, $request, $folderId)->deleteFolder();

        return new EmptyResponse;
    }

    /**
     * FolderData: the name; the optional id is only used by imports.
     */
    private static function folderData(Request $request): string
    {
        $data = Body::json($request);
        $data->optionalString('id');

        return $data->string('name');
    }

    private function folder(Headers $headers, Request $request, string $folderId): Folder
    {
        return Folder::findByUuidAndUser(Uuid::parameter($request, $folderId), $headers->user->uuid)
            ?? throw ApiError::message('Invalid folder', logMessage: 'Folder does not exist or belongs to another user');
    }
}
