<?php

namespace App\Support;

/**
 * A CBOR byte string, kept apart from text strings.
 */
final readonly class CborBytes
{
    public function __construct(
        public string $bytes,
    ) {}
}
