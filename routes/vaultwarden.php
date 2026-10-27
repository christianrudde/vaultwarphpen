<?php

use App\Http\Controllers\CoreController;
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
