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
     * The system's getaddrinfo (the hosts file included), with the sockets
     * extension; without it, IPv4 from the system's resolver and IPv6 from DNS.
     *
     * @return list<string> The addresses of the name
     */
    public function lookup(string $domain): array
    {
        $addresses = [];
        if (function_exists('socket_addrinfo_lookup')) {
            foreach (@socket_addrinfo_lookup($domain, null, ['ai_socktype' => SOCK_STREAM]) ?: [] as $info) {
                $address = socket_addrinfo_explain($info)['ai_addr'] ?? null;
                $ip = is_array($address) ? ($address['sin6_addr'] ?? $address['sin_addr'] ?? null) : null;
                if (is_string($ip)) {
                    $addresses[] = $ip;
                }
            }

            return array_values(array_unique($addresses));
        }

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
