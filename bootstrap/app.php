<?php

use App\Config\Config;
use App\Http\ErrorRenderer;
use App\Http\Middleware\AppHeaders;
use App\Http\Middleware\Cors;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Vaultwarden mounts its routes below the path of DOMAIN.
        using: function (): void {
            Route::prefix(app(Config::class)->string('domain_path'))->group(base_path('routes/vaultwarden.php'));
        },
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only Vaultwarden's response fairings. Laravel's defaults would trim strings,
        // turn empty strings into null and answer CORS on their own. Cors is listed
        // first so that it sees the response last, like Rocket attaches it after AppHeaders.
        $middleware->use([Cors::class, AppHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(fn (Throwable $exception, Request $request) => app(ErrorRenderer::class)->render($exception, $request));
    })->create();
