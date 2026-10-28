<?php

namespace App\Parity\Watch;

use Illuminate\Support\Str;

/**
 * Something the watch reports, as one GitHub issue.
 */
final readonly class Finding
{
    /**
     * @param  string  $key  Stable across runs: "<source>:<id>", e.g. "vaultwarden-advisory:GHSA-…"
     * @param  list<string>  $labels  Besides "watch"
     * @param  bool  $resolvable  Whether its issue closes once the source no longer reports it
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $body,
        public array $labels,
        public bool $resolvable = false,
    ) {}

    public function source(): string
    {
        return Str::before($this->key, ':');
    }
}
