<?php

namespace Tests\Unit\Support;

use App\Support\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * get_valid_host and should_block_host of src/http_client.rs, with the
 * tests Vaultwarden has for them.
 */
#[Parity('config:HTTP_REQUEST_BLOCK_REGEX')]
#[Parity('config:HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS')]
#[Parity('config:ICON_BLACKLIST_REGEX')]
#[Parity('config:ICON_BLACKLIST_NON_GLOBAL_IPS')]
class HttpClientHostTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function hosts(): iterable
    {
        yield 'a domain' => ['example.com', 'example.com'];
        yield 'upper case' => ['EXAMPLE.com', 'example.com'];
        yield 'dotted decimal' => ['127.0.0.1', '127.0.0.1'];
        yield 'single decimal' => ['2130706433', '127.0.0.1'];
        yield 'hex' => ['0x7f000001', '127.0.0.1'];
        yield 'dotted hex' => ['0x7f.0.0.1', '127.0.0.1'];
        yield 'octal' => ['017700000001', '127.0.0.1'];
        yield 'dotted octal' => ['0177.0.0.01', '127.0.0.1'];
        yield 'ipv6' => ['[::1]', '[::1]'];
        yield 'ipv4-mapped ipv6' => ['[::ffff:127.0.0.1]', '[::ffff:7f00:1]'];
        yield 'punycode passes' => ['xn--deadbeafcaf-lbb.test', 'xn--deadbeafcaf-lbb.test'];
        yield 'unicode gets punycoded' => ['deadbeafcafé.test', 'xn--deadbeafcaf-lbb.test'];
        yield 'unicode tld' => ['deadbeaf.café', 'deadbeaf.xn--caf-dma'];
        yield 'emoji' => ['🛡️.test', 'xn--t88h.test'];
        yield 'percent escapes' => ['%41.com', 'a.com'];
        yield 'invalid punycode' => ['xn--', null];
        yield 'underscore' => ['dead_beaf.cafe', null];
        yield 'label too long' => [str_repeat('a', 64).'.test', null];
        yield 'domain too long' => [str_repeat('a.', 130).'test', null];
        yield 'empty label' => ['a..b', null];
        yield 'trailing dot' => ['example.com.', null];
        yield 'leading hyphen' => ['-a.com', null];
        yield 'a port' => ['example.com:443', null];
        yield 'credentials' => ['user@example.com', null];
        yield 'a path' => ['example.com%2Fx', null];
        yield 'a tab' => ["exam\tple.com", null];
        yield 'not an address' => ['1.2.3.4.5', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('hosts')]
    public function test_valid_hosts(string $host, ?string $valid): void
    {
        $this->assertSame($valid, HttpClient::validHost($host));
    }

    public function test_non_global_addresses_are_blocked_by_default(): void
    {
        $this->assertSame("IP '127.0.0.1' is not a global IP!", HttpClient::blockReason('127.0.0.1'));
        $this->assertSame("IP '169.254.169.254' is not a global IP!", HttpClient::blockReason((string) HttpClient::validHost('2852039166')));
        $this->assertSame("IP '::ffff:127.0.0.1' is not a global IP!", HttpClient::blockReason('[::ffff:7f00:1]'));
        $this->assertSame("IP 'fc00::1' is not a global IP!", HttpClient::blockReason('[fc00::1]'));
        $this->assertSame("IP 10.0.0.1 for domain 'intranet.example' is not a global IP!", HttpClient::blockReason('10.0.0.1', 'intranet.example'));
        $this->assertNull(HttpClient::blockReason('8.8.8.8'));
        // Names are only checked against the regex; the resolver checks their addresses.
        $this->assertNull(HttpClient::blockReason('localhost'));

        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS' => 'false']);
        $this->assertNull(HttpClient::blockReason('127.0.0.1'));
    }

    public function test_the_deprecated_icon_settings_still_block(): void
    {
        $this->changeVaultwardenEnvironment(['ICON_BLACKLIST_NON_GLOBAL_IPS' => 'false', 'ICON_BLACKLIST_REGEX' => '^blocked\.']);

        $this->assertNull(HttpClient::blockReason('127.0.0.1'));
        $this->assertSame("Blocked domain: 'blocked.example' matched HTTP_REQUEST_BLOCK_REGEX", HttpClient::blockReason('blocked.example'));
    }

    public function test_the_block_regex(): void
    {
        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS' => 'false', 'HTTP_REQUEST_BLOCK_REGEX' => '(^|\.)internal$|^10\.']);

        $this->assertSame("Blocked domain: 'db.internal' matched HTTP_REQUEST_BLOCK_REGEX", HttpClient::blockReason('db.internal'));
        $this->assertSame("Blocked domain: '10.1.2.3' matched HTTP_REQUEST_BLOCK_REGEX", HttpClient::blockReason('10.1.2.3'));
        // An address a name resolved to.
        $this->assertSame("Blocked domain: 'x.example (10.1.2.3)' matched HTTP_REQUEST_BLOCK_REGEX", HttpClient::blockReason('10.1.2.3', 'x.example'));
        $this->assertNull(HttpClient::blockReason('internal.example.com'));
    }
}
