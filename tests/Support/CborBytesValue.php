<?php

namespace Tests\Support;

/**
 * A CBOR byte string to encode.
 */
final readonly class CborBytesValue
{
    public function __construct(
        public string $bytes,
    ) {}
}
