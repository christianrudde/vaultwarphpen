<?php

use App\Http\Controllers\CoreController;
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
