<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * util::Cached: a response that may be cached for a while, with
 * Cache-Control and the matching Expires date.
 */
final class Cached
{
    /**
     * Seven days.
     *
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function long(Response $response, bool $immutable): Response
    {
        return self::ttl($response, 604_800, $immutable);
    }

    /**
     * Ten minutes.
     *
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function short(Response $response, bool $immutable): Response
    {
        return self::ttl($response, 600, $immutable);
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function ttl(Response $response, int $ttl, bool $immutable): Response
    {
        $response->headers->set('Cache-Control', $immutable ? "public, immutable, max-age={$ttl}" : "public, max-age={$ttl}");
        $response->headers->set('Expires', gmdate('D, j M Y H:i:s', time() + $ttl).' GMT');

        return $response;
    }
}
