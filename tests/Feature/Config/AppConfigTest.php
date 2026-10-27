<?php

namespace Tests\Feature\Config;

use App\Config\Config;
use App\Templates\TemplateRenderer;
use Illuminate\Support\Facades\DB;
use Tests\Attributes\Parity;
use Tests\TestCase;

/**
 * The application's services follow Vaultwarden's settings.
 */
class AppConfigTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir().'/vw-app-'.bin2hex(random_bytes(6));
        mkdir($this->base.'/data', recursive: true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));

        parent::tearDown();
    }

    #[Parity('config:DATABASE_URL')]
    #[Parity('config:DATABASE_CONN_INIT')]
    public function test_the_vaultwarden_connection_follows_database_url(): void
    {
        touch("{$this->base}/data/vault.db");
        $this->useConfig([
            'DATABASE_URL' => "sqlite://{$this->base}/data/vault.db",
            'DATABASE_CONN_INIT' => 'PRAGMA user_version = 7; PRAGMA cache_size = 123;',
        ]);

        $connection = DB::connection('vaultwarden');

        $this->assertSame('sqlite', $connection->getDriverName());
        $this->assertSame("{$this->base}/data/vault.db", $connection->getConfig('database'));
        $this->assertEquals((object) ['user_version' => 7], $connection->selectOne('PRAGMA user_version'));
        $this->assertEquals((object) ['cache_size' => 123], $connection->selectOne('PRAGMA cache_size'));
        $this->assertEquals((object) ['foreign_keys' => 0], $connection->selectOne('PRAGMA foreign_keys'));
    }

    #[Parity('config:TEMPLATES_FOLDER')]
    #[Parity('config:RELOAD_TEMPLATES')]
    public function test_templates_come_from_the_configured_folder(): void
    {
        mkdir("{$this->base}/custom");
        file_put_contents("{$this->base}/custom/404.hbs", 'first {{urlpath}}');
        $this->useConfig(['TEMPLATES_FOLDER' => "{$this->base}/custom", 'RELOAD_TEMPLATES' => 'true']);
        $renderer = $this->app->make(TemplateRenderer::class);

        $this->assertSame('first /x', $renderer->render('404', ['urlpath' => '/x']));

        file_put_contents("{$this->base}/custom/404.hbs", 'second {{urlpath}}');
        $this->assertSame('second /x', $renderer->render('404', ['urlpath' => '/x']));
    }

    /**
     * @param  array<string, string>  $env
     */
    private function useConfig(array $env): void
    {
        $this->app->instance(Config::class, Config::load(static fn (string $key): ?string => $env[$key] ?? null, $this->base));
    }
}
