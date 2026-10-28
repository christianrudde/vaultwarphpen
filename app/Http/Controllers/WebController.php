<?php

namespace App\Http\Controllers;

use App\Auth\Jwt;
use App\Auth\JwtIssuer;
use App\Http\ApiError;
use App\Http\Data\Query;
use App\Http\EmptyResponse;
use App\Http\EtagCached;
use App\Http\FileResponse;
use App\Http\HttpStatus;
use App\Http\Json;
use App\Models\Attachment;
use App\Support\Dates;
use App\Support\Uuid;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * src/api/web.rs
 */
final readonly class WebController
{
    /**
     * STATIC_FILES: the images and scripts Vaultwarden embeds, by name, with
     * their folder in resources/vaultwarden/static and their content type.
     */
    private const array STATIC_FILES = [
        '404.png' => ['images', 'image/png'],
        'mail-github.png' => ['images', 'image/png'],
        'logo-gray.png' => ['images', 'image/png'],
        'error-x.svg' => ['images', 'image/svg+xml'],
        'hibp.png' => ['images', 'image/png'],
        'vaultwarden-icon.png' => ['images', 'image/png'],
        'vaultwarden-favicon.png' => ['images', 'image/png'],
        '404.css' => ['scripts', 'text/css; charset=utf-8'],
        'admin.css' => ['scripts', 'text/css; charset=utf-8'],
        'admin.js' => ['scripts', 'text/javascript'],
        'admin_settings.js' => ['scripts', 'text/javascript'],
        'admin_users.js' => ['scripts', 'text/javascript'],
        'admin_organizations.js' => ['scripts', 'text/javascript'],
        'admin_diagnostics.js' => ['scripts', 'text/javascript'],
        'bootstrap.css' => ['scripts', 'text/css; charset=utf-8'],
        'bootstrap.bundle.js' => ['scripts', 'text/javascript'],
        'jdenticon-3.3.0.js' => ['scripts', 'text/javascript'],
        'datatables.js' => ['scripts', 'text/javascript'],
        'datatables.css' => ['scripts', 'text/css; charset=utf-8'],
    ];

    public function __construct(
        private DatabaseManager $databases,
        private Jwt $jwt,
    ) {}

    /**
     * GET /attachments/{cipher_id}/{file_id}?token=: the file, for whoever has
     * the download token of the attachment. Anything else is a 404.
     */
    public function attachments(Request $request, string $cipherId, string $fileId): FileResponse
    {
        $cipherId = Uuid::parameter($request, $cipherId);
        $fileId = Uuid::idParameter($request, $fileId);
        // Without a token the request forwards to the web vault's catch-all.
        $token = Query::first($request, 'token') ?? throw new HttpStatus(404, 'No token.');

        try {
            $claims = $this->jwt->decode($token, JwtIssuer::FileDownload);
        } catch (ApiError) {
            throw new HttpStatus(404, 'Invalid token.');
        }
        if (($claims['sub'] ?? null) !== $cipherId || ($claims['file_id'] ?? null) !== $fileId) {
            throw new HttpStatus(404, 'The token is for another file.');
        }

        $path = Attachment::path($cipherId, $fileId);
        if (! is_file($path)) {
            throw new HttpStatus(404, 'No such file.');
        }

        return new FileResponse($path);
    }

    /**
     * GET /vw_static/{filename}: the embedded files, with an ETag of their SHA-256.
     *
     * @throws ApiError
     */
    public function staticFiles(Request $request, string $filename): EtagCached
    {
        [$folder, $contentType] = self::STATIC_FILES[$filename] ?? throw ApiError::message("Static file not found: {$filename}");
        $content = (string) file_get_contents(resource_path("vaultwarden/static/{$folder}/{$filename}"));

        return new EtagCached($request, $content, $contentType, hash('sha256', $content));
    }

    /**
     * GET /alive
     */
    public function alive(): Response
    {
        $this->database();

        return Json::response(Dates::format(Dates::now()));
    }

    /**
     * HEAD /alive
     */
    public function aliveHead(): EmptyResponse
    {
        $this->database();

        return new EmptyResponse;
    }

    /**
     * The DbConn guard: without a database connection the request fails with 503.
     */
    private function database(): void
    {
        try {
            $this->databases->connection()->getPdo();
        } catch (Throwable $exception) {
            throw new HttpException(503, 'No database connection.', $exception);
        }
    }
}
