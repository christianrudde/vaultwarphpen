<?php

namespace Tests\Feature\Http;

use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * Routes are mounted below the path of DOMAIN, like in src/main.rs.
 */
#[Parity('config:DOMAIN')]
class BasePathTest extends TestCase
{
    protected function vaultwardenEnvironment(): array
    {
        return ['DOMAIN' => 'https://example.com/vault/'];
    }

    public function test_routes_live_below_the_domain_path(): void
    {
        $this->get('/vault/api/version')->assertStatus(200);
        $this->get('/vault/api/missing')->assertStatus(404)->assertJsonPath('error.reason', 'Not Found');

        // Outside of the mount points only Rocket's default catcher answers.
        $outside = $this->get('/api/version')->assertStatus(404)->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('<small>Rocket</small>', (string) $outside->getContent());
    }
}
