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
}
