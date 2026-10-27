<?php

namespace App\Support;

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
}
