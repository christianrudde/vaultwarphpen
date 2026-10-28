<?php

namespace Tests\Differential;

use Tests\Attributes\Parity;

/**
 * The web vault of src/api/web.rs, from the files that
 * .github/scripts/differential.sh puts into WEB_VAULT_FOLDER.
 */
class WebVaultTest extends DifferentialTestCase
{
    #[Parity('route:GET /')]
    #[Parity('route:HEAD /')]
    #[Parity('route:GET /index.html')]
    #[Parity('route:GET /{p..}')]
    #[Parity('route:GET /app-id.json')]
    #[Parity('route:GET /.well-known/apple-app-site-association')]
    #[Parity('template:404')]
    public function test_files_of_the_web_vault(): void
    {
        $this->assertSameFlows(fn (Server $server): array => [
            'index' => $server->send('GET', '/'),
            'index by HEAD' => $server->send('HEAD', '/'),
            'index.html' => $server->send('GET', '/index.html'),
            'script' => $server->send('GET', '/app/main.js'),
            'image' => $server->send('GET', '/images/icon.svg'),
            'unknown type' => $server->send('GET', '/app/data.unknown'),
            'upper case extension' => $server->send('GET', '/app/STYLE.CSS'),
            'connector' => $server->send('GET', '/webauthn-connector.html'),
            'dot segments' => $server->send('GET', '/app/x/%2e%2e/main.js'),
            'empty segments' => $server->send('GET', '/app//main.js'),
            'hidden file' => $server->send('GET', '/.hidden'),
            'hidden file as JSON' => $server->send('GET', '/.hidden', ['Accept' => 'application/json']),
            'encoded slash' => $server->send('GET', '/app/%2Fmain.js'),
            'trailing colon' => $server->send('GET', '/app/main.js:'),
            'leading star' => $server->send('GET', '/app/*main.js'),
            'missing' => $server->send('GET', '/app/missing.js'),
            'missing API route' => $server->send('GET', '/api/missing'),
            'bad id' => $server->send('GET', '/attachments/not-a-uuid/abc?token=x'),
            'app id' => $server->send('GET', '/app-id.json'),
            'apple app site association' => $server->send('GET', '/.well-known/apple-app-site-association'),
            'other well-known file' => $server->send('GET', '/.well-known/security.txt'),
        ]);
    }

    #[Parity('route:GET /css/vaultwarden.css')]
    #[Parity('template:scss/vaultwarden.scss')]
    #[Parity('template:scss/user.vaultwarden.scss')]
    public function test_the_style_changes(): void
    {
        $this->assertSameFlows(function (Server $server): array {
            $steps = ['css' => $css = $server->send('GET', '/css/vaultwarden.css')];
            $etag = (string) $css->header('etag');
            $steps['css not modified'] = $server->send('GET', '/css/vaultwarden.css', ['If-None-Match' => $etag]);
            $steps['css with another tag'] = $server->send('GET', '/css/vaultwarden.css', ['If-None-Match' => '"other"']);

            return $steps;
        });
    }
}
