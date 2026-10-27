<?php

namespace App\Providers;

use App\Database\VaultwardenMigrator;
use App\Templates\TemplateRenderer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(VaultwardenMigrator::class, fn (): VaultwardenMigrator => new VaultwardenMigrator(
            database_path('vaultwarden'),
        ));

        $this->app->singleton(TemplateRenderer::class, fn (): TemplateRenderer => new TemplateRenderer(
            builtinPath: resource_path('vaultwarden/templates'),
            overridePath: config()->string('vaultwarden.templates_folder'),
            vaultwardenVersion: config()->string('vaultwarden.version'),
            webVaultVersion: config()->string('vaultwarden.web_vault_version'),
            reload: config()->boolean('vaultwarden.reload_templates'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
