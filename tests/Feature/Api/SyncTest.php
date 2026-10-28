<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\Folder;
use App\Models\Send;
use App\Models\User;
use App\Support\Dates;
use App\Support\Random;
use Tests\Attributes\Parity;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * GET /api/sync (src/api/core/ciphers.rs) and the equivalent domains of src/api/core/mod.rs.
 */
class SyncTest extends TestCase
{
    use UsesVaultwardenDatabase;

    /**
     * @var array<string, string>
     */
    private array $auth;

    private User $user;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        $this->user = User::new('sync@example.com');
        $this->user->setPassword('hash', 'user-key', false, null);
        $this->user->save();
        $device = Device::new(Random::uuid(), $this->user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);
        $this->auth = ['Authorization' => 'Bearer '.LoginClaims::default($device, $this->user, AuthMethod::Password, 'cli', $jwt)->token($jwt)];
    }

    #[Parity('asset:global_domains.json')]
    public function test_sync_of_an_empty_vault(): void
    {
        $globals = json_decode((string) file_get_contents(resource_path('vaultwarden/static/global_domains.json')), true);
        $this->assertIsArray($globals);

        $this->get('/api/sync', $this->auth)->assertStatus(200)
            ->assertJsonPath('object', 'sync')
            ->assertJsonPath('profile.id', $this->user->uuid)
            ->assertJsonPath('folders', [])
            ->assertJsonPath('collections', [])
            ->assertJsonPath('policies', [])
            ->assertJsonPath('policiesNew', [])
            ->assertJsonPath('ciphers', [])
            ->assertJsonPath('sends', [])
            ->assertJsonPath('domains.object', 'domains')
            ->assertJsonPath('domains.equivalentDomains', [])
            ->assertJsonCount(count($globals), 'domains.globalEquivalentDomains')
            ->assertJsonPath('domains.globalEquivalentDomains.0', ['domains' => ['ameritrade.com', 'tdameritrade.com'], 'excluded' => false, 'type' => 2])
            ->assertJsonPath('userDecryption', [
                'masterPasswordUnlock' => [
                    'kdf' => ['iterations' => 600000, 'kdfType' => 0, 'memory' => null, 'parallelism' => null],
                    'masterKeyEncryptedUserKey' => 'user-key',
                    'masterKeyWrappedUserKey' => 'user-key',
                    'salt' => 'sync@example.com',
                ],
                'userKeyId' => null,
            ]);
    }

    public function test_query_and_client_version(): void
    {
        $this->get('/api/sync?excludeDomains=true', $this->auth)->assertJsonPath('domains', null);
        $this->get('/api/sync?excludeDomains', $this->auth)->assertJsonPath('domains', null);
        $this->get('/api/sync?excludeDomains=Off', $this->auth)->assertJsonPath('domains.object', 'domains');
        $this->get('/api/sync?excludeDomains=1', $this->auth)->assertStatus(404)->assertJsonPath('error.code', 404);
        // The first of repeated values counts; the others are not parsed.
        $this->get('/api/sync?excludeDomains=true&excludeDomains=1', $this->auth)->assertJsonPath('domains', null);
        $this->get('/api/sync?excludeDomains=1&excludeDomains=true', $this->auth)->assertStatus(404);
        $this->get('/api/sync')->assertStatus(401);

        $folder = Folder::new($this->user->uuid, '2.folder');
        $folder->save();
        $this->postJson('/api/ciphers', ['type' => 5, 'name' => '2.ssh', 'encryptedFor' => $this->user->uuid,
            'sshKey' => ['privateKey' => '2.p', 'publicKey' => '2.q', 'keyFingerprint' => '2.f']], $this->auth)->assertStatus(200);
        $this->postJson('/api/ciphers', ['type' => 2, 'name' => '2.note', 'encryptedFor' => $this->user->uuid,
            'secureNote' => ['type' => 0], 'folderId' => $folder->uuid], $this->auth)->assertStatus(200);

        $this->get('/api/sync', $this->auth)->assertJsonCount(1, 'ciphers')->assertJsonPath('ciphers.0.folderId', $folder->uuid)
            ->assertJsonPath('folders.0.id', $folder->uuid);
        $this->get('/api/sync', [...$this->auth, 'Bitwarden-Client-Version' => '2024.11.0'])->assertJsonCount(1, 'ciphers');
        $this->get('/api/sync', [...$this->auth, 'Bitwarden-Client-Version' => '2025.1.0-beta'])->assertJsonCount(1, 'ciphers');
        $this->get('/api/sync', [...$this->auth, 'Bitwarden-Client-Version' => '2024.12.0'])->assertJsonCount(2, 'ciphers');
        $this->get('/api/sync', [...$this->auth, 'Bitwarden-Client-Version' => '2025.10.1+build.7'])->assertJsonCount(2, 'ciphers');
        $this->get('/api/sync', [...$this->auth, 'Bitwarden-Client-Version' => '2025.01.0'])->assertJsonCount(1, 'ciphers');
    }

    public function test_sends_are_listed(): void
    {
        $send = new Send;
        $send->uuid = '00112233-4455-6677-8899-aabbccddeeff';
        $send->user_uuid = $this->user->uuid;
        $send->name = '2.send';
        $send->atype = Send::TYPE_FILE;
        $send->data = '{"FileName":"2.file","Size":1234,"SizeName":"1.21 KB"}';
        $send->akey = '2.key';
        $send->password_hash = "\x01\x02\xfe";
        $send->access_count = 0;
        $send->creation_date = Dates::now();
        $send->revision_date = Dates::now();
        $send->deletion_date = Dates::now();
        $send->disabled = false;
        $send->save();

        $this->get('/api/sync', $this->auth)
            ->assertJsonPath('sends.0.accessId', 'ABEiM0RVZneImaq7zN3u_w')
            ->assertJsonPath('sends.0.file', ['fileName' => '2.file', 'size' => '1234', 'sizeName' => '1.21 KB'])
            ->assertJsonPath('sends.0.text', null)
            ->assertJsonPath('sends.0.password', 'AQL-')
            ->assertJsonPath('sends.0.authType', 1)
            ->assertJsonPath('sends.0.hideEmail', false)
            ->assertJsonPath('sends.0.expirationDate', null);
    }

    public function test_equivalent_domains(): void
    {
        $this->putJson('/api/settings/domains', ['excludedGlobalEquivalentDomains' => [2], 'equivalentDomains' => [['a.example', 'b.example']]], $this->auth)
            ->assertStatus(200)->assertContent('{}');
        $this->assertSame('[2]', $this->excludedGlobals());

        $this->get('/api/settings/domains', $this->auth)
            ->assertJsonPath('equivalentDomains', [['a.example', 'b.example']])
            ->assertJsonPath('globalEquivalentDomains.0.type', 2)
            ->assertJsonPath('globalEquivalentDomains.0.excluded', true);
        $this->get('/api/sync', $this->auth)->assertJsonPath('domains.globalEquivalentDomains.0.type', 3);

        $this->postJson('/api/settings/domains', ['equivalentDomains' => [[1]]], $this->auth)->assertStatus(422);
        $this->postJson('/api/settings/domains', ['excludedGlobalEquivalentDomains' => ['2']], $this->auth)->assertStatus(422);
        $this->call('POST', '/api/settings/domains', [], [], [], $this->transformHeadersToServerVars($this->auth), '{}')->assertContent('{}');
        $this->assertSame('[]', $this->excludedGlobals());
    }

    private function excludedGlobals(): ?string
    {
        return User::findByUuid($this->user->uuid)?->excluded_globals;
    }
}
