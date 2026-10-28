<?php

namespace Tests;

use App\Config\Config;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Router;
use Illuminate\Testing\PendingCommand;

abstract class TestCase extends BaseTestCase
{
    /**
     * A data folder of its own for every test, so that a local config.json or
     * RSA key never leaks in.
     */
    protected string $dataFolder;

    /**
     * @var list<string>
     */
    private array $setEnvironment = [];

    protected function setUp(): void
    {
        $this->dataFolder = sys_get_temp_dir().'/vw-test-'.bin2hex(random_bytes(6));
        mkdir($this->dataFolder, recursive: true);

        // Set before the application boots, which reads the settings and registers the routes.
        $this->setEnvironment(['DATA_FOLDER' => $this->dataFolder, ...$this->vaultwardenEnvironment()]);

        parent::setUp();
    }

    /**
     * Change settings during a test; the configuration is read again when next used.
     *
     * @param  array<string, string>  $environment
     */
    protected function changeVaultwardenEnvironment(array $environment): void
    {
        $this->setEnvironment($environment);
        $this->app->forgetInstance(Config::class);
        // Routes keep the controllers they made, with the configuration they were given.
        foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
    }

    /**
     * artisan() with the console output mocked, as it is in tests.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->setEnvironment as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        $this->setEnvironment = [];
        exec('rm -rf '.escapeshellarg($this->dataFolder));
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function setEnvironment(array $environment): void
    {
        foreach ($environment as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
            $this->setEnvironment[] = $key;
        }
    }

    /**
     * Vaultwarden settings for the application under test.
     *
     * @return array<string, string>
     */
    protected function vaultwardenEnvironment(): array
    {
        return [];
    }
}
