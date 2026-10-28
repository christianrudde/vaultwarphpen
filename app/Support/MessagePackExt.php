<?php

namespace App\Support;

/**
 * A MessagePack extension value: its type and data.
 */
final readonly class MessagePackExt
{
    public function __construct(
        public int $type,
        public string $data,
    ) {}
}
