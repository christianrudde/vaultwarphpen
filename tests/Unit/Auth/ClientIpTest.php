<?php

namespace Tests\Unit\Auth;

use App\Auth\ClientIp;
use App\Config\Config;
use App\Support\IpNetwork;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Attributes\Parity;

/**
 * auth::ClientIp, including the cases of Vaultwarden's own tests.
 */
#[Parity('config:IP_HEADER')]
#[Parity('config:_IP_HEADER_ENABLED')]
#[Parity('config:IP_HEADER_TRUSTED_PROXIES')]
class ClientIpTest extends TestCase
{
    /**
     * @param  list<string>  $values
     */
    private static function fromHeader(array $values): ?string
    {
        // Vaultwarden's tests trust private IPv4 and loopback addresses.
        return ClientIp::fromHeaderValues($values, static function (string $ip): bool {
            $network = IpNetwork::parse($ip);

            return in_array($ip, ['127.0.0.1', '::1'], true)
                || ($network !== null && (IpNetwork::parse('10.0.0.0/8')?->contains($ip) || IpNetwork::parse('172.16.0.0/12')?->contains($ip)
                    || IpNetwork::parse('192.168.0.0/16')?->contains($ip) || IpNetwork::parse('127.0.0.0/8')?->contains($ip)));
        });
    }

    public function test_ip_header_single_value(): void
    {
        $this->assertSame('203.0.113.5', self::fromHeader(['203.0.113.5']));
        $this->assertSame('10.0.0.7', self::fromHeader(['10.0.0.7']));
        $this->assertSame('2001:db8::1', self::fromHeader(['2001:db8::1']));
    }

    public function test_ip_header_uses_rightmost_untrusted_entry(): void
    {
        $this->assertSame('203.0.113.5', self::fromHeader(['198.51.100.1, 203.0.113.5']));
        $this->assertSame('203.0.113.5', self::fromHeader(['198.51.100.1, 203.0.113.5, 10.0.0.2, 127.0.0.1']));
        $this->assertSame('192.168.1.10', self::fromHeader(['192.168.1.10, 10.0.0.2']));
    }

    public function test_ip_header_reads_every_occurrence(): void
    {
        $this->assertSame('203.0.113.5', self::fromHeader(['198.51.100.1', '203.0.113.5']));
        $this->assertSame('203.0.113.5', self::fromHeader(['198.51.100.1', '203.0.113.5, 10.0.0.2']));
    }

    public function test_ip_header_malformed_entries(): void
    {
        $this->assertNull(self::fromHeader([]));
        $this->assertNull(self::fromHeader(['']));
        $this->assertNull(self::fromHeader(['203.0.113.5, not-an-ip']));
        $this->assertSame('10.0.0.2', self::fromHeader(['not-an-ip, 10.0.0.2']));
    }

    /**
     * @return iterable<string, array{array<string, string>, string, array<string, string>, string}>
     */
    public static function requests(): iterable
    {
        yield 'header from a local proxy' => [[], '127.0.0.1', ['X-Real-IP' => '203.0.113.9'], '203.0.113.9'];
        yield 'header from the internet is ignored' => [[], '8.8.4.4', ['X-Real-IP' => '203.0.113.9'], '8.8.4.4'];
        yield 'header disabled' => [['IP_HEADER' => 'none'], '127.0.0.1', ['X-Real-IP' => '203.0.113.9'], '127.0.0.1'];
        yield 'listed proxy' => [['IP_HEADER_TRUSTED_PROXIES' => '198.51.100.0/24'], '198.51.100.7', ['X-Real-IP' => '203.0.113.9'], '203.0.113.9'];
        yield 'all proxies' => [['IP_HEADER_TRUSTED_PROXIES' => 'all'], '203.0.113.1', ['X-Real-IP' => '203.0.113.9'], '203.0.113.9'];
        yield 'mapped local address' => [[], '::ffff:10.1.2.3', ['X-Real-IP' => '203.0.113.9'], '203.0.113.9'];
        yield 'forwarded chain' => [['IP_HEADER' => 'X-Forwarded-For'], '10.0.0.1', ['X-Forwarded-For' => '203.0.113.9, 10.0.0.5'], '203.0.113.9'];
    }

    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $headers
     */
    #[DataProvider('requests')]
    public function test_client_ip(array $env, string $remote, array $headers, string $expected): void
    {
        $base = sys_get_temp_dir().'/vw-ip-'.bin2hex(random_bytes(4));
        mkdir("{$base}/data", recursive: true);
        $config = Config::load(static fn (string $key): ?string => $env[$key] ?? null, $base);

        $server = ['REMOTE_ADDR' => $remote];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $this->assertSame($expected, ClientIp::resolve(Request::create('/', server: $server), $config));
        exec('rm -rf '.escapeshellarg($base));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['8.8.8.8', true];
        yield 'private v4' => ['192.168.1.1', false];
        yield 'shared v4' => ['100.64.0.1', false];
        yield 'benchmarking v4' => ['198.19.0.1', false];
        yield 'reserved .9' => ['192.0.0.9', true];
        yield 'reserved .8' => ['192.0.0.8', false];
        yield 'broadcast' => ['255.255.255.255', false];
        yield 'public v6' => ['2606:4700::1111', true];
        yield 'documentation v6' => ['2001:db8::1', false];
        yield 'unique local v6' => ['fd00::1', false];
        yield 'link local v6' => ['fe80::1', false];
        yield 'mapped v6' => ['::ffff:8.8.8.8', false];
        yield 'turn anycast' => ['2001:1::2', true];
        yield 'ietf v6' => ['2001:2::1', false];
    }

    #[DataProvider('addresses')]
    public function test_is_global(string $ip, bool $global): void
    {
        $this->assertSame($global, IpNetwork::isGlobal($ip));
    }
}
