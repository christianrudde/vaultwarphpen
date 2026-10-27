<?php

namespace App\Http\Middleware;

use App\Config\Config;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers Vaultwarden adds to every response (util::AppHeaders).
 *
 * Symfony normalizes Cache-Control, so the default is sent as
 * `max-age=0, no-cache, no-store, private` instead of `no-cache, no-store, max-age=0`.
 */
final readonly class AppHeaders
{
    private const string PERMISSIONS_POLICY = 'accelerometer=(), ambient-light-sensor=(), autoplay=(), battery=(), camera=(), '
        .'display-capture=(), document-domain=(), encrypted-media=(), execution-while-not-rendered=(), '
        .'execution-while-out-of-viewport=(), fullscreen=(), geolocation=(), gyroscope=(), keyboard-map=(), magnetometer=(), '
        .'microphone=(), midi=(), payment=(), picture-in-picture=(), screen-wake-lock=(), sync-xhr=(), usb=(), web-share=(), '
        .'xr-spatial-tracking=()';

    // The closing quote of object-src is missing in Vaultwarden too.
    private const string IMAGE_CSP = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; script-src 'none'; "
        ."frame-src 'none'; object-src 'none";

    public function __construct(
        private Config $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $path = $request->getPathInfo();

        // Extra headers can break WebSocket upgrades behind reverse proxies.
        if (str_ends_with($path, 'notifications/hub') || str_ends_with($path, 'notifications/anonymous-hub')) {
            $connection = $request->headers->get('connection');
            $upgrade = $request->headers->get('upgrade');
            if ($connection !== null && $upgrade !== null
                && str_contains(strtolower($connection), 'upgrade') && str_contains(strtolower($upgrade), 'websocket')) {
                $response->headers->remove('X-Frame-Options');
                $response->headers->remove('X-Content-Type-Options');
                $response->headers->remove('Permissions-Policy');

                return $response;
            }
        }

        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-XSS-Protection', '0');

        // Images and the external icon redirect must stay loadable from the clients.
        $isImage = str_starts_with((string) $response->headers->get('Content-Type'), 'image/')
            || $request->route()?->getName() === 'icons::icon_external';
        if (! $isImage) {
            $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        }

        // The *-connector.html pages are opened by the clients for WebAuthn and Duo.
        if (str_ends_with($path, 'connector.html')) {
            $response->headers->remove('X-Frame-Options');
        } else {
            $response->headers->set('Content-Security-Policy', $isImage ? self::IMAGE_CSP : $this->contentSecurityPolicy());
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        // Symfony reports this computed value when nothing set Cache-Control.
        if ($response->headers->get('Cache-Control') === 'no-cache, private') {
            $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0');
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        return "default-src 'none'; font-src 'self'; manifest-src 'self'; base-uri 'self'; form-action 'self'; "
            ."media-src 'self'; object-src 'self' blob:; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; "
            ."child-src 'self' https://*.duosecurity.com https://*.duofederal.com; "
            ."frame-src 'self' https://*.duosecurity.com https://*.duofederal.com; "
            ."frame-ancestors 'self' chrome-extension://nngceckbapebfimnlniiiahkandclblb "
            .'chrome-extension://jbkfoedolllekgbhcbcoahefnbanhhlh moz-extension://* '
            .$this->config->string('allowed_iframe_ancestors').'; '
            ."img-src 'self' data: https://haveibeenpwned.com ".$this->config->string('_icon_service_csp').'; '
            ."connect-src 'self' https://api.pwnedpasswords.com https://api.2fa.directory https://app.simplelogin.io/api/ "
            .'https://app.addy.io/api/ https://api.fastmail.com/ https://api.forwardemail.net '
            .$this->config->string('allowed_connect_src').';';
    }
}
