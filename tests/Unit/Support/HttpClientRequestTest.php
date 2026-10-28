<?php

namespace Tests\Unit\Support;

use App\Http\ApiError;
use App\Support\BlockedHost;
use App\Support\DnsResolver;
use App\Support\HttpClient;
use App\Support\HttpRequestFailed;
use RuntimeException;
use Tests\Fakes\DnsResolverFake;
use Tests\TestCase;
use Uri\WhatWg\Url;

/**
 * Outgoing requests against local servers: the checks of the redirect
 * policy and of the DNS resolver in src/http_client.rs, on every hop and
 * for every address, and one timeout for the whole request.
 */
class HttpClientRequestTest extends TestCase
{
    /**
     * @var list<resource>
     */
    private static array $processes = [];

    private static string $folder = '';

    // An HTTP server that answers with what it received, or redirects.
    private static int $port = 0;

    // A server that sends its answer one byte at a time, without end.
    private static int $slowPort = 0;

    public static function setUpBeforeClass(): void
    {
        self::$folder = sys_get_temp_dir().'/vw-http-'.bin2hex(random_bytes(6));
        mkdir(self::$folder);
        file_put_contents(self::$folder.'/router.php', <<<'PHP'
            <?php
            parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
            if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/redirect') {
                http_response_code((int) ($query['code'] ?? 302));
                header('Location: '.$query['to']);

                return;
            }
            header('Content-Type: application/json');
            echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'host' => $_SERVER['HTTP_HOST'] ?? null,
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null, 'referer' => $_SERVER['HTTP_REFERER'] ?? null,
                'body' => file_get_contents('php://input')]);
            PHP);
        file_put_contents(self::$folder.'/slow.php', <<<'PHP'
            <?php
            $server = stream_socket_server('tcp://127.0.0.1:'.$argv[1]);
            while ($client = stream_socket_accept($server, -1)) {
                foreach (str_split("HTTP/1.1 200 OK\r\nX-Slow: ".str_repeat('a', 1000)) as $byte) {
                    if (@fwrite($client, $byte) === false) {
                        break;
                    }
                    usleep(100_000);
                }
                fclose($client);
            }
            PHP);

        self::$port = self::freePort();
        self::start([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, self::$folder.'/router.php']);
        self::$slowPort = self::freePort();
        self::start([PHP_BINARY, self::$folder.'/slow.php', (string) self::$slowPort]);

        foreach ([self::$port, self::$slowPort] as $port) {
            for ($attempt = 0; @fsockopen('127.0.0.1', $port) === false; $attempt++) {
                if ($attempt === 50) {
                    throw new RuntimeException("The test server on port {$port} did not start.");
                }
                usleep(100_000);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$processes as $process) {
            proc_terminate($process);
            proc_close($process);
        }
        self::$processes = [];
        exec('rm -rf '.escapeshellarg(self::$folder));
    }

    protected function vaultwardenEnvironment(): array
    {
        // The test servers are local; the regex blocks what the tests say.
        return ['HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS' => 'false', 'HTTP_REQUEST_BLOCK_REGEX' => '^localhost$'];
    }

    public function test_every_redirect_is_checked(): void
    {
        $origin = 'http://127.0.0.1:'.self::$port;
        [$status, $body] = HttpClient::send('GET', "{$origin}/redirect?to=".urlencode("{$origin}/echo"));
        $this->assertSame(200, $status);
        $this->assertSame('GET', $this->decode($body)['method']);

        $this->expectBlocked("Blocked domain: 'localhost' matched HTTP_REQUEST_BLOCK_REGEX",
            fn () => HttpClient::send('GET', "{$origin}/redirect?to=".urlencode('http://localhost:'.self::$port.'/echo')));
        $this->expectBlocked("Blocked domain: 'localhost' matched HTTP_REQUEST_BLOCK_REGEX",
            fn () => HttpClient::get(Url::parse("{$origin}/redirect?to=".urlencode('http://localhost:'.self::$port.'/echo')) ?? $this->fail(), [], 5));
    }

    public function test_hosts_are_checked_as_the_url_crate_reads_them(): void
    {
        $this->expectBlocked("Blocked domain: 'localhost' matched HTTP_REQUEST_BLOCK_REGEX",
            fn () => HttpClient::send('GET', 'http://LOCALHOST:'.self::$port.'/echo'));

        // A regex with an escaped slash is still a regex, not a pattern that never matches.
        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_REGEX' => 'internal\/path|^localhost$']);
        $this->assertSame("Blocked domain: 'localhost' matched HTTP_REQUEST_BLOCK_REGEX", HttpClient::blockReason('localhost'));
    }

    public function test_every_address_of_a_name_is_checked(): void
    {
        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_NON_GLOBAL_IPS' => 'true']);
        $this->app->instance(DnsResolver::class, new DnsResolverFake([
            'six.example' => ['::1'],
            'both.example' => ['93.184.215.14', 'fd00::1'],
            'mapped.example' => ['::ffff:169.254.169.254'],
        ]));

        $this->expectBlocked("IP ::1 for domain 'six.example' is not a global IP!",
            fn () => HttpClient::get(Url::parse('http://six.example/') ?? $this->fail(), [], 5));
        $this->expectBlocked("IP fd00::1 for domain 'both.example' is not a global IP!",
            fn () => HttpClient::send('GET', 'https://both.example/auth/v2/check'));
        $this->expectBlocked("IP ::ffff:169.254.169.254 for domain 'mapped.example' is not a global IP!",
            fn () => HttpClient::send('GET', 'http://mapped.example/'));
    }

    public function test_the_connection_goes_to_the_address_that_was_checked(): void
    {
        // The name exists for this test only: the request reaches the server only through the checked address.
        $this->app->instance(DnsResolver::class, new DnsResolverFake(['pinned.example' => ['127.0.0.1']]));

        [$status, $body] = HttpClient::send('GET', 'http://pinned.example:'.self::$port.'/echo');
        $this->assertSame(200, $status);
        $this->assertSame('pinned.example:'.self::$port, $this->decode($body)['host']);
    }

    public function test_redirects_change_the_request_like_reqwest(): void
    {
        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_REGEX' => '^blocked\.']);
        $origin = 'http://127.0.0.1:'.self::$port;
        $other = 'http://localhost:'.self::$port;

        // A 302 goes on as a GET without the body; credentials stay with their host.
        [, $body] = HttpClient::send('POST', "{$origin}/redirect?code=302&to=".urlencode("{$other}/echo"), ['Authorization' => 'Basic secret'], 'data');
        $this->assertSame(['method' => 'GET', 'host' => 'localhost:'.self::$port, 'authorization' => null,
            'referer' => "{$origin}/redirect?code=302&to=".urlencode("{$other}/echo"), 'body' => ''], $this->decode($body));

        // A 307 to the same host repeats the request.
        [, $body] = HttpClient::send('POST', "{$origin}/redirect?code=307&to=%2Fecho", ['Authorization' => 'Basic secret'], 'data');
        $this->assertSame('POST', $this->decode($body)['method']);
        $this->assertSame('Basic secret', $this->decode($body)['authorization']);
        $this->assertSame('data', $this->decode($body)['body']);
    }

    public function test_one_timeout_covers_the_whole_request(): void
    {
        $start = microtime(true);
        try {
            HttpClient::get(Url::parse('http://127.0.0.1:'.self::$slowPort.'/') ?? $this->fail(), [], 1);
            $this->fail('The request should time out.');
        } catch (HttpRequestFailed $failure) {
            $this->assertNotInstanceOf(BlockedHost::class, $failure);
        }
        $this->assertLessThan(2.5, microtime(true) - $start);
    }

    /**
     * @param  callable(): mixed  $request
     */
    private function expectBlocked(string $reason, callable $request): void
    {
        try {
            $request();
            $this->fail("The request should be blocked: {$reason}");
        } catch (BlockedHost|ApiError $blocked) {
            $this->assertSame($reason, $blocked->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  list<string>  $command
     */
    private static function start(array $command): void
    {
        $process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if ($process === false) {
            throw new RuntimeException('Cannot start '.implode(' ', $command));
        }
        self::$processes[] = $process;
    }

    private static function freePort(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0') ?: throw new RuntimeException('No free port.');
        $address = (string) stream_socket_get_name($probe, false);
        fclose($probe);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
