<?php

namespace App\Support;

use App\Config\Config;
use stdClass;

/**
 * The web vault in WEB_VAULT_FOLDER: its version (util::get_active_web_release
 * and config::WEB_VAULT_VERSION) and how Rocket finds its files (the PathBuf
 * segments guard and NamedFile's content types).
 */
final class WebVault
{
    /**
     * Rocket's known extensions (rocket_http 0.5.2, known_media_types.rs), compared without case.
     */
    private const array CONTENT_TYPES = [
        'txt' => 'text/plain; charset=utf-8',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'xml' => 'text/xml; charset=utf-8',
        'opf' => 'application/oebps-package+xml',
        'xhtml' => 'application/xhtml+xml',
        'csv' => 'text/csv; charset=utf-8',
        'js' => 'text/javascript',
        'mjs' => 'text/javascript',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'flac' => 'audio/flac',
        'wav' => 'audio/wav',
        'webm' => 'video/webm',
        'weba' => 'audio/webm',
        'ogg' => 'video/ogg',
        'ogv' => 'video/ogg',
        'pdf' => 'application/pdf',
        'ttf' => 'application/font-sfnt',
        'otf' => 'application/font-sfnt',
        'woff' => 'application/font-woff',
        'woff2' => 'font/woff2',
        'mp3' => 'audio/mpeg',
        'mp4' => 'video/mp4',
        'mpeg4' => 'video/mp4',
        'wasm' => 'application/wasm',
        'aac' => 'audio/aac',
        'ics' => 'text/calendar',
        'bin' => 'application/octet-stream',
        'iso' => 'application/octet-stream',
        'dmg' => 'application/octet-stream',
        'mpg' => 'video/mpeg',
        'mpeg' => 'video/mpeg',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'mov' => 'video/quicktime',
        'zip' => 'application/zip',
        'cbz' => 'application/vnd.comicbook+zip',
        'cbr' => 'application/vnd.comicbook-rar',
        'rar' => 'application/vnd.rar',
        'epub' => 'application/epub+zip',
        'md' => 'text/markdown; charset=utf-8',
        'markdown' => 'text/markdown; charset=utf-8',
        'exe' => 'application/vnd.microsoft.portable-executable',
    ];

    /**
     * get_active_web_release: the version named in the web vault's version file.
     */
    public static function activeRelease(Config $config): string
    {
        $folder = $config->path('web_vault_folder');
        foreach (["{$folder}/vw-version.json", "{$folder}/version.json"] as $file) {
            $json = is_file($file) ? json_decode((string) file_get_contents($file)) : null;
            if ($json instanceof stdClass && is_string($json->version ?? null)) {
                return ltrim($json->version, 'v');
            }
        }

        return 'Version file missing';
    }

    /**
     * WEB_VAULT_VERSION, which the "webver" template helper compares: the first
     * year.month.patch of the active release, or 2024.6.2 when there is none
     * that semver accepts (no leading zeros).
     */
    public static function version(Config $config): string
    {
        if (preg_match('/(\d{4})\.(\d{1,2})\.(\d{1,2})/', self::activeRelease($config), $match) === 1) {
            $parts = array_slice($match, 1);
            if (! array_filter($parts, static fn (string $part): bool => strlen($part) > 1 && $part[0] === '0')) {
                return implode('.', $parts);
            }
        }

        return '2024.6.2';
    }

    /**
     * Rocket's PathBuf guard on the segments of a path: decoded, empty ones
     * skipped, ".." removes the last one. Null where Rocket refuses the path:
     * hidden files, segments starting with "*" or ending with ":", ">" or
     * "<", and encoded slashes.
     */
    public static function relativePath(string $rawPath): ?string
    {
        $segments = [];
        foreach (explode('/', $rawPath) as $segment) {
            $segment = rawurldecode($segment);
            if ($segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } elseif (str_starts_with($segment, '.') || str_starts_with($segment, '*') || str_contains($segment, '/')
                || str_ends_with($segment, ':') || str_ends_with($segment, '>') || str_ends_with($segment, '<')) {
                return null;
            } else {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    /**
     * NamedFile's content type: Rocket's type for the extension, or none.
     */
    public static function contentType(string $path): ?string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return self::CONTENT_TYPES[strtolower($extension)] ?? null;
    }
}
