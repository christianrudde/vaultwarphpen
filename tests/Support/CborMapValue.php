<?php

namespace Tests\Support;

/**
 * A CBOR map to encode, with its entries in order.
 */
final readonly class CborMapValue
{
    /**
     * @param  list<array{int|string, mixed}>  $entries
     */
    public function __construct(
        public array $entries,
    ) {}
}
