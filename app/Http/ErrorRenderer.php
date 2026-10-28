<?php

namespace App\Http;

use App\Config\Config;
use App\Http\Controllers\AdminController;
use App\Templates\TemplateRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * Turns exceptions into Vaultwarden's responses: its error type, or the
 * catcher Rocket picks for the status and path (src/main.rs registers them
 * per mount point).
 */
final readonly class ErrorRenderer
{
    public function __construct(
        private Config $config,
        private TemplateRenderer $templates,
    ) {}

    public function render(Throwable $exception, Request $request): Response
    {
        return match (true) {
            $exception instanceof ApiError => $exception->toResponse(),
            $exception instanceof Unauthorized => $this->catch($request, 401),
            $exception instanceof HttpStatus => $this->catch($request, $exception->status),
            // Errors converted with `?` carry the name of their kind as message.
            $exception instanceof QueryException, $exception instanceof PDOException => ApiError::message('Db')->toResponse(),
            // Rocket answers unmatched methods like unmatched paths.
            $exception instanceof MethodNotAllowedHttpException => $this->catch($request, 404),
            $exception instanceof HttpExceptionInterface => $this->catch($request, $exception->getStatusCode()),
            // A panicking handler.
            default => $this->catch($request, 500),
        };
    }

    public function catch(Request $request, int $status): Response
    {
        $base = $this->config->string('domain_path');

        if ($status === 401 && self::isUnder($request, "{$base}/admin") && AdminController::enabled($this->config)) {
            return app(AdminController::class)->adminLogin($request);
        }

        if ($status === 404 && self::isUnder($request, "{$base}/api")) {
            // core::api_not_found
            return Json::response(Json::sorted(['error' => [
                'code' => 404,
                'reason' => 'Not Found',
                'description' => 'The requested resource could not be found.',
            ]]), 404);
        }

        if ($status === 404 && $this->config->bool('web_vault_enabled') && self::isUnder($request, $base === '' ? '/' : $base)) {
            // web::not_found
            $html = $this->templates->render('404', ['urlpath' => $base]);

            return new Response($html, 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        return DefaultCatcher::response($request, $status);
    }

    /**
     * Whether the path lies below a mount point, segment by segment.
     */
    private static function isUnder(Request $request, string $mount): bool
    {
        $path = $request->getPathInfo();
        $mount = rtrim($mount, '/');

        return $mount === '' || $path === $mount || str_starts_with($path, "{$mount}/");
    }
}
