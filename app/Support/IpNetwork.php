<?php

namespace App\Support;

/**
 * An IP address or CIDR range like Rust's IpNet.
 */
final readonly class IpNetwork
{
    private function __construct(
        public string $address,
        public int $prefix,
    ) {}

    /**
     * parse_trusted_proxy: a CIDR range, or a single address.
     */
    public static function parse(string $entry): ?self
    {
        $entry = trim($entry);
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        $binary = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton((string) $address);
        if ($binary === false) {
            return null;
        }

        $bits = strlen($binary) * 8;
        if ($prefix === null) {
            return new self($binary, $bits);
        }
        if (! preg_match('/^[0-9]{1,3}$/D', $prefix) || (int) $prefix > $bits) {
            return null;
        }

        return new self($binary, (int) $prefix);
    }

    public function contains(string $ip): bool
    {
        $binary = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        if ($binary === false || strlen($binary) !== strlen($this->address)) {
            return false;
        }

        $bytes = intdiv($this->prefix, 8);
        $bits = $this->prefix % 8;
        if (substr($binary, 0, $bytes) !== substr($this->address, 0, $bytes)) {
            return false;
        }
        if ($bits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $bits)) & 0xFF;

        return (ord($binary[$bytes]) & $mask) === (ord($this->address[$bytes]) & $mask);
    }
}
