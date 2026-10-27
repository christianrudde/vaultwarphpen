<?php

use App\Http\Controllers\AccountsController;
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
