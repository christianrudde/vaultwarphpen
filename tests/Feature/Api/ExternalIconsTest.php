<?php

namespace Tests\Feature\Api;

use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * An external ICON_SERVICE: icons::icon_external answers with a redirect.
 */
#[Parity('route:GET /icons/{host}/icon.png#icon_external')]
#[Parity('config:ICON_SERVICE')]
#[Parity('config:_ICON_SERVICE_URL')]
#[Parity('config:ICON_REDIRECT_CODE')]
class ExternalIconsTest extends TestCase
{
    protected function vaultwardenEnvironment(): array
    {
        return ['ICON_SERVICE' => 'bitwarden'];
    }

    public function test_a_redirect_to_the_service(): void
    {
        $response = $this->get('/icons/example.com/icon.png')->assertStatus(302)
            ->assertHeader('Location', 'https://icons.bitwarden.net/example.com/icon.png')
            ->assertHeader('Cache-Control', 'immutable, max-age=2592000, public')->assertHeader('Expires')
            ->assertHeaderMissing('Content-Type')->assertHeaderMissing('Cross-Origin-Resource-Policy');
        $this->assertSame('', $response->getContent());

        // The host as get_valid_host reads it.
        $this->get('/icons/B%C3%BCcher.example/icon.png')->assertHeader('Location', 'https://icons.bitwarden.net/xn--bcher-kva.example/icon.png');
        $this->get('/icons/[2001:4860::8888]/icon.png')->assertHeader('Location', 'https://icons.bitwarden.net/[2001:4860::8888]/icon.png');
    }

    public function test_other_services_and_codes(): void
    {
        foreach ([301 => 'https://icons.duckduckgo.com/ip3/example.com.ico', 307 => 'https://www.google.com/s2/favicons?domain=example.com&sz=32',
            308 => 'https://icons.example.net/example.com?size=32'] as $code => $location) {
            $service = match ($code) {
                301 => 'duckduckgo',
                307 => 'google',
                default => 'https://icons.example.net/{}?size=32',
            };
            $this->changeVaultwardenEnvironment(['ICON_SERVICE' => $service, 'ICON_REDIRECT_CODE' => (string) $code, 'ICON_CACHE_TTL' => '60']);

            $this->get('/icons/example.com/icon.png')->assertStatus($code)->assertHeader('Location', $location)
                ->assertHeader('Cache-Control', 'immutable, max-age=60, public');
        }
    }

    public function test_invalid_and_blocked_hosts_are_not_found(): void
    {
        $this->get('/icons/dead_beaf.cafe/icon.png')->assertStatus(404)->assertHeaderMissing('Location')->assertHeaderMissing('Expires');
        $this->get('/icons/127.0.0.1/icon.png')->assertStatus(404)->assertHeaderMissing('Location');

        $this->changeVaultwardenEnvironment(['HTTP_REQUEST_BLOCK_REGEX' => 'example']);
        $this->get('/icons/example.com/icon.png')->assertStatus(404);
    }
}
