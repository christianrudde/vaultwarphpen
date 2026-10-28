<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Rocket's Accept header handling.
 */
final class Accept
{
    /**
     * Accept::preferred(): the media type with the highest quality, the first
     * among equals, without its parameters; null without the header.
     */
    public static function preferred(Request $request): ?string
    {
        $header = $request->headers->get('Accept');
        if ($header === null || trim($header) === '') {
            return null;
        }

        $preferred = null;
        $best = -1.0;
        foreach (explode(',', $header) as $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $quality = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                if (preg_match('/^q\s*=\s*([0-9.]+)$/i', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }
            if ($quality > $best) {
                $best = $quality;
                $preferred = strtolower($parts[0]);
            }
        }

        return $preferred;
    }

    public static function prefersJson(Request $request): bool
    {
        return self::preferred($request) === 'application/json';
    }

    /**
     * Request::format(): the media type of the body (Content-Type) for
     * methods that carry one, otherwise the preferred one of Accept.
     */
    public static function format(Request $request): ?string
    {
        if (! self::carriesPayload($request)) {
            return self::preferred($request);
        }

        $type = $request->headers->get('Content-Type');
        $type = $type === null ? '' : strtolower(trim(explode(';', $type)[0]));

        return $type === '' ? null : $type;
    }

    /**
     * Whether a route with `format = "<type>"` matches: the media type of the
     * body must be fully given and match, the accepted one must match when
     * there is one. A `*` matches anything.
     */
    public static function matchesFormat(Request $request, string $format): bool
    {
        $type = self::format($request);
        if ($type === null) {
            return ! self::carriesPayload($request);
        }

        $requested = explode('/', $type, 2) + [1 => ''];
        if (self::carriesPayload($request) && in_array('*', $requested, true)) {
            return false;
        }
        foreach (explode('/', $format, 2) as $index => $part) {
            if ($part !== '*' && $requested[$index] !== '*' && $part !== $requested[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Method::supports_payload
     */
    private static function carriesPayload(Request $request): bool
    {
        return in_array($request->getMethod(), ['POST', 'PUT', 'DELETE', 'PATCH'], true);
    }
}
