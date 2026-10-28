<?php

namespace App\Support;

/**
 * The lookup of the DNS resolver in src/http_client.rs, which asks for IPv4
 * and IPv6 addresses (LookupIpStrategy::Ipv4AndIpv6). HttpClient checks what
 * it returns and connects to nothing else. Tests put a fake in the container.
 */
class DnsResolver
{
    /**
     * @return list<string> The addresses of the name: IPv4 from the system's
     *                      resolver (hosts file included), IPv6 from DNS
     */
    public function lookup(string $domain): array
    {
        $addresses = gethostbynamel($domain) ?: [];
        $records = @dns_get_record($domain, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
