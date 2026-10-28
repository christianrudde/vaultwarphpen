<?php

namespace Tests\Fakes;

use App\Support\DnsResolver;

/**
 * Names with the addresses a test gives them; any other name resolves to nothing.
 */
final class DnsResolverFake extends DnsResolver
{
    /**
     * @param  array<string, list<string>>  $records
     */
    public function __construct(
        private readonly array $records,
    ) {}

    public function lookup(string $domain): array
    {
        return $this->records[$domain] ?? [];
    }
}
