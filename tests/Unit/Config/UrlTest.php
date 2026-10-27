<?php

namespace Tests\Unit\Config;

use App\Config\Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expectations follow Rust's url crate.
 */
class UrlTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function urls(): iterable
    {
        yield 'plain' => ['https://vault.example.com', 'https://vault.example.com', ''];
        yield 'default port' => ['https://vault.example.com:443/', 'https://vault.example.com', ''];
        yield 'other port' => ['http://vault.example.com:8080/vw/', 'http://vault.example.com:8080', '/vw'];
        yield 'case' => ['HTTPS://Vault.Example.COM/Path', 'https://vault.example.com', '/Path'];
        yield 'dot segments' => ['https://a.example/x/./y/../z', 'https://a.example', '/x/z'];
        yield 'encoded path' => ['https://a.example/my vault', 'https://a.example', '/my%20vault'];
        yield 'query and fragment' => ['https://a.example/p?q=1#f', 'https://a.example', '/p'];
        yield 'credentials' => ['https://user:pw@a.example/p', 'https://a.example', '/p'];
        yield 'ipv6' => ['http://[::1]:8000/', 'http://[::1]:8000', ''];
        yield 'opaque' => ['mailto:a@example.com', 'null', 'a@example.com'];
        yield 'no scheme' => ['vault.example.com', '', ''];
        yield 'no host' => ['https://', '', ''];
        yield 'bad port' => ['https://a.example:99999', '', ''];
    }

    #[DataProvider('urls')]
    public function test_origin_and_path(string $url, string $origin, string $path): void
    {
        $this->assertSame($origin, Url::origin($url));
        $this->assertSame($path, Url::pathWithoutTrailingSlash($url));
        $this->assertSame($origin !== '', Url::isValid($url));
    }

    public function test_domain_is_only_set_for_domain_names(): void
    {
        $this->assertSame('vault.example.com', Url::domain('https://Vault.Example.com:8443'));
        $this->assertNull(Url::domain('http://192.168.1.2'));
        $this->assertNull(Url::domain('http://[::1]'));
        $this->assertNull(Url::domain('invalid'));
    }
}
