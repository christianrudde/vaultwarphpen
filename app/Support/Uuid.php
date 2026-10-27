<?php

namespace App\Support;

use App\Http\HttpStatus;
use Illuminate\Http\Request;

/**
 * UUIDs as the uuid crate parses them (the UuidFromParam derive): plain or
 * hyphenated hex, optionally in braces or as a urn:uuid: URN, any case.
 */
final class Uuid
{
    public static function isValid(string $value): bool
    {
        $hyphenated = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

        return preg_match("/^(?:[0-9a-fA-F]{32}|{$hyphenated}|\\{{$hyphenated}\\}|urn:uuid:{$hyphenated})$/D", $value) === 1;
    }

    /**
     * An id path parameter. Rocket parses it after the request guards; a bad
     * one forwards the request to the next matching route. For GET requests
     * that is the web vault's catch-all, which finds no such file: 404.
     *
     * @throws HttpStatus
     */
    public static function parameter(Request $request, string $value): string
    {
        if (! self::isValid($value)) {
            throw new HttpStatus(in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 404 : 422, 'Path parameter is not a UUID.');
        }

        return $value;
    }

    /**
     * An id path parameter of the IdFromParam derive (attachment and send
     * file ids): ASCII letters, digits and hyphens. A bad one forwards like
     * a bad UUID.
     *
     * @throws HttpStatus
     */
    public static function idParameter(Request $request, string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9-]*$/D', $value)) {
            throw new HttpStatus(in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 404 : 422, 'Path parameter is not an id.');
        }

        return $value;
    }
}
