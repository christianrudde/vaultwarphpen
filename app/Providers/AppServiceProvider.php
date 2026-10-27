<?php

namespace App\Providers;

use App\Auth\Headers;
use App\Auth\Jwt;
use App\Auth\RsaKeys;
use App\Auth\SendHeaders;
use App\Config\Config;
use App\Database\DatabaseUrl;
use App\Database\SqlStatements;
use App\Database\VaultwardenMigrator;
use App\Templates\TemplateRenderer;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Read from the raw environment: Laravel's env() would turn values like "null" or "(true)" into PHP values.
        $this->app->singleton(Config::class, fn (): Config => Config::load(
            static fn (string $key): ?string => Env::getRepository()->get($key),
            base_path(),
        ));

        // Loads (or creates) the key file once per request.
        $this->app->singleton(RsaKeys::class);

        // Request guards, resolved when a controller type-hints them, like Rocket's FromRequest.
        $this->app->bind(Headers::class, fn (): Headers => Headers::fromRequest(
            $this->app->make(Request::class),
            $this->app->make(Config::class),
            $this->app->make(Jwt::class),
        ));
        $this->app->bind(SendHeaders::class, fn (): SendHeaders => SendHeaders::fromRequest(
            $this->app->make(Request::class),
            $this->app->make(Jwt::class),
        ));

        $this->app->singleton(VaultwardenMigrator::class, fn (): VaultwardenMigrator => new VaultwardenMigrator(
            database_path('vaultwarden'),
        ));

        $this->app->singleton(TemplateRenderer::class, function (): TemplateRenderer {
            $config = $this->app->make(Config::class);

            return new TemplateRenderer(
                builtinPath: resource_path('vaultwarden/templates'),
                overridePath: $config->path('templates_folder'),
                vaultwardenVersion: config()->string('vaultwarden.version'),
                webVaultVersion: config()->string('vaultwarden.web_vault_version'),
                reload: $config->bool('reload_templates'),
            );
        });

        // The "vaultwarden" connection is configured by DATABASE_URL, like Vaultwarden's own.
        $this->app->afterResolving('db', function (DatabaseManager $databases): void {
            $databases->extend('vaultwarden', function (array $_, string $name) {
                $config = $this->app->make(Config::class);
                $connection = $this->app->make(ConnectionFactory::class)->make(DatabaseUrl::connection($config), $name);

                foreach (SqlStatements::split($config->string('database_conn_init')) as $statement) {
                    $connection->getPdo()->exec($statement);
                }

                return $connection;
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
