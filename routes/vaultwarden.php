<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\CiphersController;
use App\Http\Controllers\CoreController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\FoldersController;
use App\Http\Controllers\IdentityController;
use App\Http\Controllers\OrganizationsController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\SendsController;
use App\Http\Controllers\WebController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vaultwarden's routes
|--------------------------------------------------------------------------
|
| Grouped by the mount points of src/main.rs, below DOMAIN's path. Each
| route is named after its Rust handler, as listed in PARITY.md.
|
*/

// api::web_routes()
Route::get('/alive', [WebController::class, 'alive'])->name('web::alive');
// Registered after the GET route, which would otherwise answer HEAD too.
Route::match(['HEAD'], '/alive', [WebController::class, 'aliveHead'])->name('web::alive_head');
Route::get('/attachments/{cipherId}/{fileId}', [WebController::class, 'attachments'])->name('web::attachments');

// api::core_routes()
Route::prefix('/api')->group(function (): void {
    Route::get('/alive', [CoreController::class, 'alive'])->name('core::alive');
    Route::get('/now', [CoreController::class, 'now'])->name('core::now');
    Route::get('/version', [CoreController::class, 'version'])->name('core::version');
    Route::get('/settings/domains', [CoreController::class, 'getSettingsDomains'])->name('core::get_settings_domains');
    Route::post('/settings/domains', [CoreController::class, 'postSettingsDomains'])->name('core::post_settings_domains');
    Route::put('/settings/domains', [CoreController::class, 'postSettingsDomains'])->name('core::put_settings_domains');

    // core::accounts
    Route::get('/accounts/profile', [AccountsController::class, 'profile'])->name('core::accounts::profile');
    Route::put('/accounts/profile', [AccountsController::class, 'putProfile'])->name('core::accounts::put_profile');
    Route::post('/accounts/profile', [AccountsController::class, 'postProfile'])->name('core::accounts::post_profile');
    Route::put('/accounts/avatar', [AccountsController::class, 'putAvatar'])->name('core::accounts::put_avatar');
    Route::get('/users/{userId}/public-key', [AccountsController::class, 'getPublicKeys'])->name('core::accounts::get_public_keys');
    Route::get('/accounts/keys', [AccountsController::class, 'getKeys'])->name('core::accounts::get_keys');
    Route::post('/accounts/keys', [AccountsController::class, 'postKeys'])->name('core::accounts::post_keys');
    Route::get('/accounts/revision-date', [AccountsController::class, 'revisionDate'])->name('core::accounts::revision_date');

    // core::ciphers
    Route::get('/sync', [CiphersController::class, 'sync'])->name('core::ciphers::sync');
    // Rocket ranks static segments above dynamic ones; Laravel takes the first match, so these come first.
    Route::delete('/ciphers', [CiphersController::class, 'deleteCiphers'])->name('core::ciphers::delete_cipher_selected');
    Route::post('/ciphers/delete', [CiphersController::class, 'deleteCiphers'])->name('core::ciphers::delete_cipher_selected_post');
    Route::put('/ciphers/delete', [CiphersController::class, 'softDeleteCiphers'])->name('core::ciphers::delete_cipher_selected_put');
    Route::delete('/ciphers/admin', [CiphersController::class, 'deleteCiphers'])->name('core::ciphers::delete_cipher_selected_admin');
    Route::post('/ciphers/delete-admin', [CiphersController::class, 'deleteCiphers'])->name('core::ciphers::delete_cipher_selected_post_admin');
    Route::put('/ciphers/delete-admin', [CiphersController::class, 'softDeleteCiphers'])->name('core::ciphers::delete_cipher_selected_put_admin');
    Route::put('/ciphers/restore', [CiphersController::class, 'restoreCiphers'])->name('core::ciphers::restore_cipher_selected');
    Route::put('/ciphers/restore-admin', [CiphersController::class, 'restoreCiphers'])->name('core::ciphers::restore_cipher_selected_admin');
    Route::post('/ciphers/move', [CiphersController::class, 'moveCiphers'])->name('core::ciphers::move_cipher_selected');
    Route::put('/ciphers/move', [CiphersController::class, 'moveCiphers'])->name('core::ciphers::move_cipher_selected_put');
    Route::post('/ciphers/purge', [CiphersController::class, 'purgeVault'])->name('core::ciphers::purge_personal_vault');
    Route::put('/ciphers/share', [CiphersController::class, 'putCipherShareSelected'])->name('core::ciphers::put_cipher_share_selected');
    Route::get('/ciphers/organization-details', [OrganizationsController::class, 'getOrgDetails'])->name('core::organizations::get_org_details');
    Route::post('/ciphers/import-organization', [OrganizationsController::class, 'postOrgImport'])->name('core::organizations::post_org_import');
    Route::post('/ciphers/bulk-collections', [OrganizationsController::class, 'postBulkCollections'])->name('core::organizations::post_bulk_collections');
    Route::post('/ciphers/create', [CiphersController::class, 'postCiphersCreate'])->name('core::ciphers::post_ciphers_create');
    Route::post('/ciphers/admin', [CiphersController::class, 'postCiphersCreate'])->name('core::ciphers::post_ciphers_admin');
    Route::post('/ciphers/import', [CiphersController::class, 'postCiphersImport'])->name('core::ciphers::post_ciphers_import');
    Route::put('/ciphers/archive', [CiphersController::class, 'archiveCiphers'])->name('core::ciphers::archive_cipher_selected');
    Route::put('/ciphers/unarchive', [CiphersController::class, 'unarchiveCiphers'])->name('core::ciphers::unarchive_cipher_selected');

    Route::get('/ciphers', [CiphersController::class, 'getCiphers'])->name('core::ciphers::get_ciphers');
    Route::get('/ciphers/{cipherId}', [CiphersController::class, 'getCipher'])->name('core::ciphers::get_cipher');
    Route::get('/ciphers/{cipherId}/admin', [CiphersController::class, 'getCipher'])->name('core::ciphers::get_cipher_admin');
    Route::get('/ciphers/{cipherId}/details', [CiphersController::class, 'getCipher'])->name('core::ciphers::get_cipher_details');
    Route::post('/ciphers', [CiphersController::class, 'postCiphers'])->name('core::ciphers::post_ciphers');
    Route::put('/ciphers/{cipherId}', [CiphersController::class, 'putCipher'])->name('core::ciphers::put_cipher');
    Route::post('/ciphers/{cipherId}', [CiphersController::class, 'putCipher'])->name('core::ciphers::post_cipher');
    Route::put('/ciphers/{cipherId}/admin', [CiphersController::class, 'putCipher'])->name('core::ciphers::put_cipher_admin');
    Route::post('/ciphers/{cipherId}/admin', [CiphersController::class, 'putCipher'])->name('core::ciphers::post_cipher_admin');
    Route::put('/ciphers/{cipherId}/partial', [CiphersController::class, 'putCipherPartial'])->name('core::ciphers::put_cipher_partial');
    Route::post('/ciphers/{cipherId}/partial', [CiphersController::class, 'putCipherPartial'])->name('core::ciphers::post_cipher_partial');
    Route::put('/ciphers/{cipherId}/share', [CiphersController::class, 'putCipherShare'])->name('core::ciphers::put_cipher_share');
    Route::post('/ciphers/{cipherId}/share', [CiphersController::class, 'putCipherShare'])->name('core::ciphers::post_cipher_share');
    Route::put('/ciphers/{cipherId}/collections', [CiphersController::class, 'putCollectionsUpdate'])->name('core::ciphers::put_collections_update');
    Route::post('/ciphers/{cipherId}/collections', [CiphersController::class, 'putCollectionsUpdate'])->name('core::ciphers::post_collections_update');
    Route::put('/ciphers/{cipherId}/collections_v2', [CiphersController::class, 'putCollections2Update'])->name('core::ciphers::put_collections2_update');
    Route::post('/ciphers/{cipherId}/collections_v2', [CiphersController::class, 'putCollections2Update'])->name('core::ciphers::post_collections2_update');
    Route::put('/ciphers/{cipherId}/collections-admin', [CiphersController::class, 'putCollectionsAdmin'])->name('core::ciphers::put_collections_admin');
    Route::post('/ciphers/{cipherId}/collections-admin', [CiphersController::class, 'putCollectionsAdmin'])->name('core::ciphers::post_collections_admin');
    Route::get('/ciphers/{cipherId}/attachment/{attachmentId}', [CiphersController::class, 'getAttachment'])->name('core::ciphers::get_attachment');
    // Before the data upload, whose attachment id would take "v2" too.
    Route::post('/ciphers/{cipherId}/attachment/v2', [CiphersController::class, 'postAttachmentV2'])->name('core::ciphers::post_attachment_v2');
    Route::post('/ciphers/{cipherId}/attachment/{attachmentId}', [CiphersController::class, 'postAttachmentV2Data'])->name('core::ciphers::post_attachment_v2_data');
    Route::post('/ciphers/{cipherId}/attachment', [CiphersController::class, 'postAttachment'])->name('core::ciphers::post_attachment');
    Route::post('/ciphers/{cipherId}/attachment-admin', [CiphersController::class, 'postAttachment'])->name('core::ciphers::post_attachment_admin');
    Route::post('/ciphers/{cipherId}/attachment/{attachmentId}/share', [CiphersController::class, 'postAttachmentShare'])->name('core::ciphers::post_attachment_share');
    Route::post('/ciphers/{cipherId}/attachment/{attachmentId}/delete-admin', [CiphersController::class, 'deleteAttachment'])->name('core::ciphers::delete_attachment_post_admin');
    Route::post('/ciphers/{cipherId}/attachment/{attachmentId}/delete', [CiphersController::class, 'deleteAttachment'])->name('core::ciphers::delete_attachment_post');
    Route::delete('/ciphers/{cipherId}/attachment/{attachmentId}', [CiphersController::class, 'deleteAttachment'])->name('core::ciphers::delete_attachment');
    Route::delete('/ciphers/{cipherId}/attachment/{attachmentId}/admin', [CiphersController::class, 'deleteAttachment'])->name('core::ciphers::delete_attachment_admin');
    Route::post('/ciphers/{cipherId}/delete', [CiphersController::class, 'deleteCipher'])->name('core::ciphers::delete_cipher_post');
    Route::post('/ciphers/{cipherId}/delete-admin', [CiphersController::class, 'deleteCipher'])->name('core::ciphers::delete_cipher_post_admin');
    Route::put('/ciphers/{cipherId}/delete', [CiphersController::class, 'softDeleteCipher'])->name('core::ciphers::delete_cipher_put');
    Route::put('/ciphers/{cipherId}/delete-admin', [CiphersController::class, 'softDeleteCipher'])->name('core::ciphers::delete_cipher_put_admin');
    Route::delete('/ciphers/{cipherId}', [CiphersController::class, 'deleteCipher'])->name('core::ciphers::delete_cipher');
    Route::delete('/ciphers/{cipherId}/admin', [CiphersController::class, 'deleteCipher'])->name('core::ciphers::delete_cipher_admin');
    Route::put('/ciphers/{cipherId}/restore', [CiphersController::class, 'restoreCipher'])->name('core::ciphers::restore_cipher_put');
    Route::put('/ciphers/{cipherId}/restore-admin', [CiphersController::class, 'restoreCipher'])->name('core::ciphers::restore_cipher_put_admin');
    Route::put('/ciphers/{cipherId}/archive', [CiphersController::class, 'archiveCipher'])->name('core::ciphers::archive_cipher_put');
    Route::put('/ciphers/{cipherId}/unarchive', [CiphersController::class, 'unarchiveCipher'])->name('core::ciphers::unarchive_cipher_put');

    // core::sends
    Route::get('/sends', [SendsController::class, 'getSends'])->name('core::sends::get_sends');
    Route::post('/sends', [SendsController::class, 'postSend'])->name('core::sends::post_send');
    Route::post('/sends/file/v2', [SendsController::class, 'postSendFileV2'])->name('core::sends::post_send_file_v2');
    Route::post('/sends/access', [SendsController::class, 'postAccess'])->name('core::sends::post_access');
    // Before the file upload, whose send id would take "access" too.
    Route::post('/sends/access/file/{fileId}', [SendsController::class, 'postAccessFile'])->name('core::sends::post_access_file');
    Route::post('/sends/{sendId}/file/{fileId}', [SendsController::class, 'postSendFileV2Data'])->name('core::sends::post_send_file_v2_data');
    Route::get('/sends/{sendId}', [SendsController::class, 'getSend'])->name('core::sends::get_send');
    Route::get('/sends/{sendId}/{fileId}', [SendsController::class, 'downloadSend'])->name('core::sends::download_send');
    Route::put('/sends/{sendId}', [SendsController::class, 'putSend'])->name('core::sends::put_send');
    Route::delete('/sends/{sendId}', [SendsController::class, 'deleteSend'])->name('core::sends::delete_send');
    Route::put('/sends/{sendId}/remove-password', [SendsController::class, 'putRemovePassword'])->name('core::sends::put_remove_password');

    // core::organizations
    Route::get('/collections', [OrganizationsController::class, 'getUserCollections'])->name('core::organizations::get_user_collections');
    Route::post('/organizations', [OrganizationsController::class, 'createOrganization'])->name('core::organizations::create_organization');
    Route::get('/organizations/{orgId}', [OrganizationsController::class, 'getOrganization'])->name('core::organizations::get_organization');
    Route::put('/organizations/{orgId}', [OrganizationsController::class, 'putOrganization'])->name('core::organizations::put_organization');
    Route::delete('/organizations/{orgId}', [OrganizationsController::class, 'deleteOrganization'])->name('core::organizations::delete_organization');
    Route::post('/organizations/{orgId}/leave', [OrganizationsController::class, 'leaveOrganization'])->name('core::organizations::leave_organization');
    Route::post('/organizations/{orgId}/keys', [OrganizationsController::class, 'postOrgKeys'])->name('core::organizations::post_org_keys');
    Route::get('/organizations/{orgId}/keys', [OrganizationsController::class, 'getOrganizationPublicKey'])->name('core::organizations::get_organization_keys');
    Route::get('/organizations/{orgId}/public-key', [OrganizationsController::class, 'getOrganizationPublicKey'])->name('core::organizations::get_organization_public_key');

    Route::get('/organizations/{orgId}/collections', [OrganizationsController::class, 'getOrgCollections'])->name('core::organizations::get_org_collections');
    Route::get('/organizations/{orgId}/collections/details', [OrganizationsController::class, 'getOrgCollectionsDetails'])->name('core::organizations::get_org_collections_details');
    Route::post('/organizations/{orgId}/collections', [OrganizationsController::class, 'postOrganizationCollections'])->name('core::organizations::post_organization_collections');
    Route::delete('/organizations/{orgId}/collections', [OrganizationsController::class, 'bulkDeleteOrganizationCollections'])->name('core::organizations::bulk_delete_organization_collections');
    Route::post('/organizations/{orgId}/collections/bulk-access', [OrganizationsController::class, 'postBulkAccessCollections'])->name('core::organizations::post_bulk_access_collections');
    Route::put('/organizations/{orgId}/collections/{colId}', [OrganizationsController::class, 'putOrganizationCollectionUpdate'])->name('core::organizations::put_organization_collection_update');
    Route::delete('/organizations/{orgId}/collections/{colId}', [OrganizationsController::class, 'deleteOrganizationCollection'])->name('core::organizations::delete_organization_collection');
    Route::get('/organizations/{orgId}/collections/{colId}/details', [OrganizationsController::class, 'getOrgCollectionDetail'])->name('core::organizations::get_org_collection_detail');
    Route::get('/organizations/{orgId}/collections/{colId}/users', [OrganizationsController::class, 'getCollectionUsers'])->name('core::organizations::get_collection_users');

    Route::get('/organizations/{orgId}/export', [OrganizationsController::class, 'getOrgExport'])->name('core::organizations::get_org_export');
    Route::post('/organizations/{orgId}/api-key', [OrganizationsController::class, 'postApiKey'])->name('core::organizations::post_api_key');
    Route::post('/organizations/{orgId}/rotate-api-key', [OrganizationsController::class, 'rotateApiKey'])->name('core::organizations::rotate_api_key');
    Route::get('/organizations/{orgId}/policies', [OrganizationsController::class, 'listPolicies'])->name('core::organizations::list_policies');
    Route::get('/organizations/{orgId}/policies/token', [OrganizationsController::class, 'listPoliciesToken'])->name('core::organizations::list_policies_token');
    Route::get('/organizations/00000000-01DC-01DC-01DC-000000000000/policies/master-password', [OrganizationsController::class, 'getDummyMasterPasswordPolicy'])->name('core::organizations::get_dummy_master_password_policy');
    Route::get('/organizations/{orgId}/policies/master-password', [OrganizationsController::class, 'getMasterPasswordPolicy'])->name('core::organizations::get_master_password_policy');
    Route::get('/organizations/{orgId}/policies/{polType}', [OrganizationsController::class, 'getPolicy'])->name('core::organizations::get_policy');
    Route::put('/organizations/{orgId}/policies/{polType}', [OrganizationsController::class, 'putPolicy'])->name('core::organizations::put_policy');

    Route::get('/organizations/{orgId}/groups', [OrganizationsController::class, 'getGroups'])->name('core::organizations::get_groups');
    Route::get('/organizations/{orgId}/groups/details', [OrganizationsController::class, 'getGroupsDetails'])->name('core::organizations::get_groups_details');
    Route::post('/organizations/{orgId}/groups', [OrganizationsController::class, 'postGroups'])->name('core::organizations::post_groups');
    Route::delete('/organizations/{orgId}/groups', [OrganizationsController::class, 'bulkDeleteGroups'])->name('core::organizations::bulk_delete_groups');
    Route::get('/organizations/{orgId}/groups/{groupId}', [OrganizationsController::class, 'getGroup'])->name('core::organizations::get_group');
    Route::put('/organizations/{orgId}/groups/{groupId}', [OrganizationsController::class, 'putGroup'])->name('core::organizations::put_group');
    Route::delete('/organizations/{orgId}/groups/{groupId}', [OrganizationsController::class, 'deleteGroup'])->name('core::organizations::delete_group');
    Route::get('/organizations/{orgId}/groups/{groupId}/details', [OrganizationsController::class, 'getGroupDetails'])->name('core::organizations::get_group_details');
    Route::get('/organizations/{orgId}/groups/{groupId}/users', [OrganizationsController::class, 'getGroupMembers'])->name('core::organizations::get_group_members');

    // The static member routes before those with a member id, which would take their names too.
    Route::get('/organizations/{orgId}/users', [OrganizationsController::class, 'getMembers'])->name('core::organizations::get_members');
    Route::get('/organizations/{orgId}/users/mini-details', [OrganizationsController::class, 'getOrgUserMiniDetails'])->name('core::organizations::get_org_user_mini_details');
    Route::post('/organizations/{orgId}/users/invite', [OrganizationsController::class, 'sendInvite'])->name('core::organizations::send_invite');
    Route::post('/organizations/{orgId}/users/reinvite', [OrganizationsController::class, 'bulkReinviteMembers'])->name('core::organizations::bulk_reinvite_members');
    Route::post('/organizations/{orgId}/users/confirm', [OrganizationsController::class, 'bulkConfirmInvite'])->name('core::organizations::bulk_confirm_invite');
    Route::post('/organizations/{orgId}/users/public-keys', [OrganizationsController::class, 'bulkPublicKeys'])->name('core::organizations::bulk_public_keys');
    Route::delete('/organizations/{orgId}/users', [OrganizationsController::class, 'bulkDeleteMember'])->name('core::organizations::bulk_delete_member');
    Route::put('/organizations/{orgId}/users/revoke', [OrganizationsController::class, 'bulkRevokeMembers'])->name('core::organizations::bulk_revoke_members');
    Route::put('/organizations/{orgId}/users/restore', [OrganizationsController::class, 'bulkRestoreMembers'])->name('core::organizations::bulk_restore_members');
    Route::get('/organizations/{orgId}/users/{memberId}', [OrganizationsController::class, 'getUser'])->name('core::organizations::get_user');
    Route::put('/organizations/{orgId}/users/{memberId}', [OrganizationsController::class, 'editMember'])->name('core::organizations::edit_member');
    Route::delete('/organizations/{orgId}/users/{memberId}', [OrganizationsController::class, 'deleteMember'])->name('core::organizations::delete_member');
    Route::post('/organizations/{orgId}/users/{memberId}/reinvite', [OrganizationsController::class, 'reinviteMember'])->name('core::organizations::reinvite_member');
    Route::post('/organizations/{orgId}/users/{memberId}/confirm', [OrganizationsController::class, 'confirmInvite'])->name('core::organizations::confirm_invite');
    Route::put('/organizations/{orgId}/users/{memberId}/revoke', [OrganizationsController::class, 'revokeMember'])->name('core::organizations::revoke_member');
    Route::put('/organizations/{orgId}/users/{memberId}/restore', [OrganizationsController::class, 'restoreMember'])->name('core::organizations::restore_member');
    Route::put('/organizations/{orgId}/users/{memberId}/restore/vnext', [OrganizationsController::class, 'restoreMember'])->name('core::organizations::restore_member_vnext');
    Route::put('/organizations/{orgId}/users/{memberId}/recover-account', [OrganizationsController::class, 'putRecoverAccount'])->name('core::organizations::put_recover_account');
    Route::get('/organizations/{orgId}/users/{memberId}/reset-password-details', [OrganizationsController::class, 'getResetPasswordDetails'])->name('core::organizations::get_reset_password_details');
    Route::put('/organizations/{orgId}/users/{userId}/reset-password-enrollment', [OrganizationsController::class, 'putResetPasswordEnrollment'])->name('core::organizations::put_reset_password_enrollment');

    // core::folders
    Route::get('/folders', [FoldersController::class, 'getFolders'])->name('core::folders::get_folders');
    Route::get('/folders/{folderId}', [FoldersController::class, 'getFolder'])->name('core::folders::get_folder');
    Route::post('/folders', [FoldersController::class, 'postFolders'])->name('core::folders::post_folders');
    Route::post('/folders/{folderId}', [FoldersController::class, 'postFolder'])->name('core::folders::post_folder');
    Route::put('/folders/{folderId}', [FoldersController::class, 'putFolder'])->name('core::folders::put_folder');
    Route::post('/folders/{folderId}/delete', [FoldersController::class, 'deleteFolderPost'])->name('core::folders::delete_folder_post');
    Route::delete('/folders/{folderId}', [FoldersController::class, 'deleteFolder'])->name('core::folders::delete_folder');

    // core::events
    Route::get('/organizations/{orgId}/events', [EventsController::class, 'getOrgEvents'])->name('core::events::get_org_events');
    Route::get('/ciphers/{cipherId}/events', [EventsController::class, 'getCipherEvents'])->name('core::events::get_cipher_events');
    Route::get('/organizations/{orgId}/users/{memberId}/events', [EventsController::class, 'getUserEvents'])->name('core::events::get_user_events');

    // core::public
    Route::post('/public/organization/import', [PublicController::class, 'ldapImport'])->name('core::public::ldap_import');
});

// api::identity_routes()
Route::prefix('/identity')->group(function (): void {
    Route::post('/connect/token', [IdentityController::class, 'login'])->name('identity::login');
    Route::post('/accounts/prelogin', [IdentityController::class, 'postPrelogin'])->name('identity::post_prelogin');
    Route::post('/accounts/prelogin/password', [IdentityController::class, 'preloginPassword'])->name('identity::prelogin_password');
    Route::post('/accounts/register/send-verification-email', [IdentityController::class, 'registerVerificationEmail'])
        ->name('identity::register_verification_email');
    Route::post('/accounts/register/finish', [IdentityController::class, 'registerFinish'])->name('identity::register_finish');
});

// api::core_events_routes()
Route::prefix('/events')->group(function (): void {
    Route::post('/collect', [EventsController::class, 'postEventsCollect'])->name('core::events::post_events_collect');
});
