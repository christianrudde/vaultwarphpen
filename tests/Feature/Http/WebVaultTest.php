<?php

namespace Tests\Feature\Http;

use App\Config\Config;
use App\Support\WebVault;
use App\Templates\TemplateRenderer;
use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * The web vault from WEB_VAULT_FOLDER (src/api/web.rs), below DOMAIN's path.
 */
#[Parity('config:WEB_VAULT_FOLDER')]
#[Parity('config:WEB_VAULT_ENABLED')]
class WebVaultTest extends TestCase
{
    private string $webVault;

    protected function vaultwardenEnvironment(): array
    {
        return ['DOMAIN' => 'https://example.com/vault', 'WEB_VAULT_FOLDER' => "{$this->dataFolder}/web-vault/"];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->webVault = "{$this->dataFolder}/web-vault";
        mkdir("{$this->webVault}/app", recursive: true);
        file_put_contents("{$this->webVault}/index.html", '<!DOCTYPE html><title>Web vault</title>');
        file_put_contents("{$this->webVault}/app/main.js", 'console.log(1);');
        file_put_contents("{$this->webVault}/app/data.unknown", 'data');
        file_put_contents("{$this->webVault}/.hidden", 'secret');
    }

    #[Parity('route:GET /')]
    #[Parity('route:HEAD /')]
    #[Parity('route:GET /index.html')]
    public function test_the_index(): void
    {
        $index = $this->get('/vault/')->assertStatus(200)->assertHeader('Content-Type', 'text/html; charset=utf-8')
            ->assertHeader('Cache-Control', 'max-age=600, public')->assertHeader('Expires');
        $this->assertSame('<!DOCTYPE html><title>Web vault</title>', $index->streamedContent());

        $head = $this->call('HEAD', '/vault/')->assertStatus(200)->assertHeaderMissing('Content-Type');
        $this->assertSame('', $head->getContent());

        $this->get('/vault/index.html')->assertStatus(303)->assertHeader('Location', '/vault/');
    }

    #[Parity('route:GET /{p..}')]
    public function test_files_of_the_web_vault(): void
    {
        $script = $this->get('/vault/app/main.js')->assertStatus(200)->assertHeader('Content-Type', 'text/javascript')
            ->assertHeader('Cache-Control', 'immutable, max-age=604800, public');
        $this->assertSame('console.log(1);', $script->streamedContent());
        $this->get('/vault/app/data.unknown')->assertStatus(200)->assertHeaderMissing('Content-Type');
        $this->assertSame('console.log(1);', $this->get('/vault/app//x/%2e%2e/main.js')->assertStatus(200)->streamedContent());

        // Hidden files and odd segments fail the guard, and no route is left.
        $this->get('/vault/.hidden')->assertStatus(422);
        $this->get('/vault/app/%2Fmain.js')->assertStatus(422);
        $this->get('/vault/app/main.js:')->assertStatus(422);
        $missing = $this->get('/vault/app/missing.js')->assertStatus(404)->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertStringContainsString('/vault/vw_static/404.png', (string) $missing->getContent());
        $this->get('/vault/app')->assertStatus(404);

        // Unknown API routes keep their JSON answer; a bad id forwards to the catch-all.
        $this->get('/vault/api/missing')->assertStatus(404)->assertJsonPath('error.code', 404);
        $this->get('/vault/attachments/not-a-uuid/abc?token=x')->assertStatus(404);
    }

    #[Parity('route:GET /app-id.json')]
    #[Parity('route:GET /.well-known/apple-app-site-association')]
    public function test_app_associations(): void
    {
        $this->get('/vault/app-id.json')->assertStatus(200)->assertHeader('Content-Type', 'application/fido.trusted-apps+json')
            ->assertHeader('Cache-Control', 'immutable, max-age=604800, public')
            ->assertExactJson(['trustedFacets' => [['ids' => ['https://example.com', 'ios:bundle-id:com.8bit.bitwarden',
                'android:apk-key-hash:dUGFzUzf3lmHSLBDBIv+WaFyZMI'], 'version' => ['major' => 1, 'minor' => 0]]]]);
        $this->get('/vault/.well-known/apple-app-site-association')->assertStatus(200)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['webcredentials' => ['apps' => ['LTZ2PFU5D6.com.8bit.bitwarden', 'LTZ2PFU5D6.com.8bit.bitwarden.beta']]]);
    }

    #[Parity('route:GET /css/vaultwarden.css')]
    #[Parity('template:scss/vaultwarden.scss')]
    #[Parity('template:scss/user.vaultwarden.scss')]
    public function test_the_style_changes(): void
    {
        $css = $this->get('/vault/css/vaultwarden.css')->assertStatus(200)->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $etag = (string) $css->headers->get('Etag');
        $this->assertSame('"'.hash('sha256', (string) $css->getContent()).'"', $etag);
        $this->assertStringContainsString('.vw-hide,head{display:none !important}', (string) $css->getContent());
        $this->assertStringNotContainsString('/*', (string) $css->getContent());
        $this->get('/vault/css/vaultwarden.css', ['If-None-Match' => $etag])->assertStatus(304);

        // Without a version file the web vault counts as 2024.6.2, which needs the older selectors.
        $this->assertStringContainsString('app-change-password>form>.form-group:nth-child(5)', $this->restyle(['PASSWORD_HINTS_ALLOWED' => 'false']));
        file_put_contents("{$this->webVault}/vw-version.json", '{"version":"v2026.8.0"}');
        $this->assertStringContainsString('.vw-password-hint', $this->restyle());

        // Broken user styles fall back to the built-in ones.
        mkdir("{$this->dataFolder}/templates/scss", recursive: true);
        file_put_contents("{$this->dataFolder}/templates/scss/user.vaultwarden.scss.hbs", '.broken { color: ');
        $this->assertStringContainsString('.vw-hide,head{', $this->restyle());
    }

    public function test_the_version_of_the_web_vault(): void
    {
        $config = $this->app->make(Config::class);
        $this->assertSame(['Version file missing', '2024.6.2'], [WebVault::activeRelease($config), WebVault::version($config)]);
        file_put_contents("{$this->webVault}/version.json", '{"version":"2026.8.0-beta"}');
        $this->assertSame(['2026.8.0-beta', '2026.8.0'], [WebVault::activeRelease($config), WebVault::version($config)]);
        file_put_contents("{$this->webVault}/vw-version.json", '{"version":"v2026.08.1"}');
        $this->assertSame(['2026.08.1', '2024.6.2'], [WebVault::activeRelease($config), WebVault::version($config)]);
    }

    public function test_without_the_web_vault(): void
    {
        $this->changeVaultwardenEnvironment(['WEB_VAULT_ENABLED' => 'false']);
        $this->refreshApplication();

        $this->get('/vault/')->assertStatus(404)->assertSee('<small>Rocket</small>', escape: false);
        $this->get('/vault/app/main.js')->assertStatus(404);
        // A bad id forwards, and without the web vault's catch-all no route is left.
        $this->get('/vault/attachments/not-a-uuid/abc?token=x')->assertStatus(422);
    }

    /**
     * The CSS after the settings, the templates or the web vault changed.
     *
     * @param  array<string, string>  $environment
     */
    private function restyle(array $environment = []): string
    {
        $this->app->forgetInstance(TemplateRenderer::class);
        $this->changeVaultwardenEnvironment($environment);

        return (string) $this->get('/vault/css/vaultwarden.css')->assertStatus(200)->getContent();
    }
}
