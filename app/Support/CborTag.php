<?php

namespace App\Support;

/**
 * A tagged CBOR item.
 */
final readonly class CborTag
{
    public function __construct(
        public int $tag,
        public mixed $value,
    ) {}
}
