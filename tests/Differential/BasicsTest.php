<?php

namespace Tests\Differential;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Attributes\Parity;

/**
 * The routes that exist so far, the catchers and the response headers.
 */
class BasicsTest extends DifferentialTestCase
{
    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function requests(): iterable
    {
        yield 'alive' => ['GET', '/alive', []];
        yield 'alive head' => ['HEAD', '/alive', []];
        yield 'api alive' => ['GET', '/api/alive', []];
        yield 'now' => ['GET', '/api/now', []];
        yield 'now head' => ['HEAD', '/api/now', []];
        yield 'version' => ['GET', '/api/version', []];
        yield 'version with query' => ['GET', '/api/version?x=1', []];
        yield 'trailing slash' => ['GET', '/api/version/', []];
        yield 'api not found' => ['GET', '/api/no-such-route', []];
        yield 'api not found for a browser' => ['GET', '/api/no-such-route', ['Accept' => 'text/html']];
        yield 'unmatched method' => ['DELETE', '/api/now', ['Accept' => 'application/json']];
        yield 'not found page' => ['GET', '/no-such-page', []];
        yield 'not found below identity' => ['GET', '/identity/no-such-route', ['Accept' => 'application/json']];
        yield 'not found below admin' => ['GET', '/admin/no/such/route', []];
        yield 'allowed origin' => ['GET', '/api/now', ['Origin' => 'http://localhost']];
        yield 'desktop origin' => ['GET', '/api/now', ['Origin' => 'bw-desktop-file://bundle']];
        yield 'foreign origin' => ['GET', '/api/now', ['Origin' => 'https://evil.example.com']];
        yield 'preflight' => ['OPTIONS', '/api/accounts/profile', [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ]];
        yield 'preflight of an existing route' => ['OPTIONS', '/api/now', ['Origin' => 'http://localhost']];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('requests')]
    #[Parity('route:GET /alive')]
    #[Parity('route:HEAD /alive')]
    #[Parity('route:GET /api/alive')]
    #[Parity('route:GET /api/now')]
    #[Parity('route:GET /api/version')]
    public function test_responses_match(string $method, string $path, array $headers): void
    {
        $this->assertSameResponses($method, $path, $headers);
    }
}
