<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Archive;
use App\Models\Cipher;
use App\Models\Device;
use App\Models\Favorite;
use App\Models\Folder;
use App\Models\User;
use App\Support\Random;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * The personal vault of src/api/core/ciphers.rs.
 */
class CipherTest extends TestCase
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

        $this->user = User::new('ciphers@example.com');
        $this->user->save();
        $device = Device::new(Random::uuid(), $this->user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);
        $this->auth = ['Authorization' => 'Bearer '.LoginClaims::default($device, $this->user, AuthMethod::Password, 'cli', $jwt)->token($jwt)];
    }

    public function test_cipher_lifecycle(): void
    {
        $folder = Folder::new($this->user->uuid, '2.folder');
        $folder->save();

        $response = $this->postJson('/api/ciphers', [
            ...$this->cipher(1, '2.login'),
            'folderId' => $folder->uuid,
            'favorite' => true,
            'fields' => [['Name' => '2.field', 'Type' => '0', 'response' => 'x']],
            'passwordHistory' => [['password' => '2.old', 'lastUsedDate' => '2026-01-02T03:04:05.123+02:00'], ['password' => null]],
            'login' => ['Username' => '2.user', 'response' => 'x', 'uris' => [['uri' => '2.uri', 'match' => '3', 'response' => 'x']]],
        ], $this->auth)->assertStatus(200)
            ->assertJsonPath('object', 'cipherDetails')
            ->assertJsonPath('name', '2.login')
            ->assertJsonPath('folderId', $folder->uuid)
            ->assertJsonPath('favorite', true)
            ->assertJsonPath('fields', [['name' => '2.field', 'type' => 0]])
            ->assertJsonPath('passwordHistory', [['lastUsedDate' => '2026-01-02T03:04:05.123000+02:00', 'password' => '2.old']])
            ->assertJsonPath('login', ['uri' => '2.uri', 'uris' => [['match' => 3, 'uri' => '2.uri']], 'username' => '2.user'])
            ->assertJsonPath('secureNote', null)
            ->assertJsonPath('permissions', ['delete' => true, 'restore' => true]);
        $id = $response->json('id');
        $this->assertIsString($id);

        $stored = Cipher::findByUuid($id);
        $this->assertNotNull($stored);
        // Stored as serde_json writes it: compact, keys sorted, original case kept.
        $this->assertSame('{"Username":"2.user","uris":[{"match":"3","uri":"2.uri"}]}', $stored->data);
        $this->assertSame('[{"Name":"2.field","Type":"0"}]', $stored->fields);

        $this->get("/api/ciphers/{$id}", $this->auth)->assertJsonPath('id', $id);
        $this->get('/api/ciphers', $this->auth)->assertJsonPath('object', 'list')->assertJsonCount(1, 'data');

        $this->putJson("/api/ciphers/{$id}", [...$this->cipher(1, '2.renamed'), 'login' => (object) [], 'archivedDate' => '2026-05-06T07:08:09Z'], $this->auth)
            ->assertJsonPath('name', '2.renamed')
            ->assertJsonPath('folderId', null)
            // Without favorite the flag stays as it is.
            ->assertJsonPath('favorite', true)
            ->assertJsonPath('archivedDate', '2026-05-06T07:08:09.000000Z');

        $this->putJson("/api/ciphers/{$id}/partial", ['folderId' => $folder->uuid, 'favorite' => false], $this->auth)
            ->assertJsonPath('folderId', $folder->uuid)
            ->assertJsonPath('favorite', false)
            ->assertJsonPath('name', '2.renamed');
        $this->assertFalse(Favorite::isFavorite($id, $this->user->uuid));

        $this->putJson("/api/ciphers/{$id}", [...$this->cipher(1, '2.again'), 'login' => (object) []], $this->auth)->assertJsonPath('archivedDate', null);
        $this->assertNull(Archive::archivedAt($id, $this->user->uuid));
    }

    public function test_invalid_ciphers(): void
    {
        $this->postJson('/api/ciphers', [...$this->cipher(1, '2.x'), 'encryptedFor' => Random::uuid(), 'login' => (object) []], $this->auth)
            ->assertStatus(422)->assertJsonPath('message', 'Invalid user cipher');
        $this->postJson('/api/ciphers', [...$this->cipher(9, '2.x'), 'login' => (object) []], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid type');
        $this->postJson('/api/ciphers', $this->cipher(1, '2.x'), $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'Data missing');
        $this->postJson('/api/ciphers', [...$this->cipher(1, '2.x'), 'login' => (object) [], 'notes' => str_repeat('n', 10001)], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'The field Notes exceeds the maximum encrypted value length of 10000 characters.');
        $this->postJson('/api/ciphers', [...$this->cipher(1, '2.x'), 'login' => (object) [], 'folderId' => Random::uuid()], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'Invalid folder');
        $this->assertSame(0, Cipher::query()->count());
    }

    public function test_a_login_that_is_a_list_breaks_like_upstream(): void
    {
        // Saved first, then serde_json panics while building the answer.
        $this->postJson('/api/ciphers', [...$this->cipher(1, '2.x'), 'login' => []], $this->auth)->assertStatus(500);
        $this->assertSame('[]', Cipher::query()->value('data'));
        $this->get('/api/ciphers', $this->auth)->assertStatus(500);
    }

    public function test_stale_client_copies_are_rejected(): void
    {
        $id = $this->postJson('/api/ciphers', [...$this->cipher(1, '2.x'), 'login' => (object) []], $this->auth)->json('id');
        $this->assertIsString($id);

        $this->putJson("/api/ciphers/{$id}", [...$this->cipher(1, '2.y'), 'login' => (object) [], 'lastKnownRevisionDate' => '2020-01-01T00:00:00Z'], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'The client copy of this cipher is out of date. Resync the client and try again.');
        // Unparsable dates are only logged.
        $this->putJson("/api/ciphers/{$id}", [...$this->cipher(1, '2.y'), 'login' => (object) [], 'lastKnownRevisionDate' => 'yesterday'], $this->auth)
            ->assertStatus(200);
    }

    public function test_ciphers_of_other_users_are_hidden(): void
    {
        $other = User::new('other@example.com');
        $other->save();
        $cipher = Cipher::new(2, '2.theirs');
        $cipher->user_uuid = $other->uuid;
        $cipher->data = '{"type":0}';
        $cipher->save();

        $this->get("/api/ciphers/{$cipher->uuid}", $this->auth)->assertStatus(400)->assertJsonPath('message', 'Cipher is not owned by user');
        $this->putJson("/api/ciphers/{$cipher->uuid}", [...$this->cipher(2, '2.mine'), 'secureNote' => []], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'Cipher is not write accessible');
        $this->putJson("/api/ciphers/{$cipher->uuid}/partial", ['favorite' => true], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'Cipher does not exist');
        $this->get('/api/ciphers', $this->auth)->assertJsonCount(0, 'data');
    }

    /**
     * @return array<string, mixed>
     */
    private function cipher(int $type, string $name): array
    {
        return ['type' => $type, 'name' => $name, 'encryptedFor' => $this->user->uuid];
    }
}
