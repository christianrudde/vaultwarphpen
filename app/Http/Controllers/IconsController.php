<?php

namespace App\Http\Controllers;

use App\Config\Config;
use App\Http\Cached;
use App\Http\HttpStatus;
use App\Http\Redirect;
use App\Icons\IconService;
use App\Support\EscapeDebug;
use App\Support\HttpClient;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * src/api/icons.rs: one of the two routes is mounted, depending on ICON_SERVICE.
 */
final readonly class IconsController
{
    public function __construct(
        private Config $config,
        private IconService $icons,
    ) {}

    /**
     * routes(): whether Vaultwarden fetches the icons itself.
     */
    public static function internal(Config $config): bool
    {
        return $config->string('icon_service') === 'internal';
    }

    /**
     * GET /icons/<host>/icon.png with an external ICON_SERVICE: a redirect
     * to the icon there. AppHeaders leaves out Cross-Origin-Resource-Policy
     * for this route, which would break the icons in the clients.
     */
    public function iconExternal(string $host): Redirect
    {
        $valid = HttpClient::validHost($host);
        if ($valid === null) {
            Log::warning('Invalid host: '.EscapeDebug::of($host));

            throw new HttpStatus(404, 'Invalid host');
        }
        if (HttpClient::blockReason($valid) !== null) {
            Log::warning("Blocked address: {$valid}");

            throw new HttpStatus(404, 'Blocked address');
        }

        $url = str_replace('{}', $valid, $this->config->string('_icon_service_url'));
        $code = $this->config->int('icon_redirect_code');
        if (! in_array($code, [301, 302, 307, 308], true)) {
            Log::error("Unexpected redirect code {$code}");

            throw new HttpStatus(404, 'Unexpected redirect code');
        }

        return Cached::ttl(new Redirect($url, $code), $this->config->int('icon_cache_ttl'), immutable: true);
    }

    /**
     * GET /icons/<host>/icon.png with the internal ICON_SERVICE: the icon of
     * the host, or the fallback icon, which is cached for ICON_CACHE_NEGTTL.
     */
    public function iconInternal(string $host): Response
    {
        $valid = HttpClient::validHost($host);
        if ($valid === null) {
            Log::warning('Invalid host: '.EscapeDebug::of($host));

            return $this->fallback();
        }
        if (HttpClient::blockReason($valid) !== null) {
            Log::warning("Blocked address: {$valid}");

            return $this->fallback();
        }

        $icon = $this->icons->icon($valid);
        if ($icon === null) {
            return $this->fallback();
        }

        return Cached::ttl(new Response($icon[0], 200, ['Content-Type' => "image/{$icon[1]}"]), $this->config->int('icon_cache_ttl'), immutable: true);
    }

    private function fallback(): Response
    {
        $icon = (string) file_get_contents(resource_path('vaultwarden/static/images/fallback-icon.png'));

        return Cached::ttl(new Response($icon, 200, ['Content-Type' => 'image/png']), $this->config->int('icon_cache_negttl'), immutable: true);
    }
}
