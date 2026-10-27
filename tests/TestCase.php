<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
        foreach (['DATA_FOLDER' => $this->dataFolder, ...$this->vaultwardenEnvironment()] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
            $this->setEnvironment[] = $key;
        }

        parent::setUp();
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
     * Vaultwarden settings for the application under test.
     *
     * @return array<string, string>
     */
    protected function vaultwardenEnvironment(): array
    {
        return [];
    }
}
