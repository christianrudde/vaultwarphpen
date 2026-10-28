<?php

namespace App\Auth;

use Illuminate\Http\Request;

/**
 * The Secure request guard (src/auth.rs): whether the client reached the
 * server over HTTPS, which decides the Secure flag of cookies.
 */
final class Secure
{
    public static function https(Request $request): bool
    {
        $protocol = $request->headers->get('X-Forwarded-Proto');
        if ($protocol === null) {
            // ROCKET_TLS: the server terminates TLS itself.
            return getenv('ROCKET_TLS') !== false || $request->server('HTTPS', 'off') !== 'off';
        }

        return $protocol === 'https';
    }
}
