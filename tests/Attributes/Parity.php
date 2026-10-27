<?php

namespace Tests\Attributes;

use Attribute;

/**
 * Links a test to the parity matrix items it covers (parity/matrix.json),
 * by id or by a prefix ending in "*", e.g. #[Parity('table:*')].
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Parity
{
    public function __construct(
        public string $id,
    ) {}
}
