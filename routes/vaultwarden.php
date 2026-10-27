<?php

use App\Http\Controllers\AccountsController;
use App\Http\Controllers\CiphersController;
use App\Http\Controllers\CoreController;
use App\Http\Controllers\FoldersController;
use App\Http\Controllers\IdentityController;
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

// api::core_routes()
Route::prefix('/api')->group(function (): void {
    Route::get('/alive', [CoreController::class, 'alive'])->name('core::alive');
    Route::get('/now', [CoreController::class, 'now'])->name('core::now');
    Route::get('/version', [CoreController::class, 'version'])->name('core::version');

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

    // core::folders
    Route::get('/folders', [FoldersController::class, 'getFolders'])->name('core::folders::get_folders');
    Route::get('/folders/{folderId}', [FoldersController::class, 'getFolder'])->name('core::folders::get_folder');
    Route::post('/folders', [FoldersController::class, 'postFolders'])->name('core::folders::post_folders');
    Route::post('/folders/{folderId}', [FoldersController::class, 'postFolder'])->name('core::folders::post_folder');
    Route::put('/folders/{folderId}', [FoldersController::class, 'putFolder'])->name('core::folders::put_folder');
    Route::post('/folders/{folderId}/delete', [FoldersController::class, 'deleteFolderPost'])->name('core::folders::delete_folder_post');
    Route::delete('/folders/{folderId}', [FoldersController::class, 'deleteFolder'])->name('core::folders::delete_folder');
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
