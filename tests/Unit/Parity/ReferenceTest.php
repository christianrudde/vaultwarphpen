<?php

namespace Tests\Unit\Parity;

use App\Parity\Reference;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReferenceTest extends TestCase
{
    private const string DIGEST = 'sha256:632375471c7c06a799335cd74dfeaef63f92f45f9cd322c337ae7ad70081e48a';

    public function test_the_web_vault_is_the_one_vaultwarden_ships(): void
    {
        $this->reference('2026.8.0', self::DIGEST)->assertWebVaultMatches();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Vaultwarden ships web vault 2026.8.0 ('.self::DIGEST.'); set "web_vault" and "web_vault_digest"');
        $this->reference('2026.8.0', 'sha256:'.str_repeat('0', 64))->assertWebVaultMatches();
    }

    public function test_the_pin_names_the_web_vault_and_its_image(): void
    {
        $reference = Reference::load(dirname(__DIR__, 3));

        $this->assertMatchesRegularExpression('/^\d{4}\.\d+\.\d+$/', $reference->webVault);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $reference->webVaultDigest);
    }

    private function reference(string $webVault, string $digest): Reference
    {
        return new Reference(
            repository: 'https://github.com/dani-garcia/vaultwarden.git',
            commit: str_repeat('0', 40),
            release: '1.37.4',
            webVault: $webVault,
            webVaultDigest: $digest,
            checkout: dirname(__DIR__, 2).'/Fixtures/vaultwarden',
        );
    }
}
