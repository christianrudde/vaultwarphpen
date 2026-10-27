<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\Folder;
use App\Models\FolderCipher;
use App\Models\User;
use App\Support\Random;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * src/api/core/folders.rs
 */
class FolderTest extends TestCase
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

        $this->user = User::new('folders@example.com');
        $this->user->save();
        $device = Device::new(Random::uuid(), $this->user->uuid, 'cli', 25);
        $device->save();
        $jwt = $this->app->make(Jwt::class);
        $this->auth = ['Authorization' => 'Bearer '.LoginClaims::default($device, $this->user, AuthMethod::Password, 'cli', $jwt)->token($jwt)];
    }

    public function test_folder_lifecycle(): void
    {
        $this->get('/api/folders', $this->auth)->assertContent('{"continuationToken":null,"data":[],"object":"list"}');

        $id = $this->postJson('/api/folders', ['name' => 'Work'], $this->auth)->assertStatus(200)
            ->assertJsonPath('name', 'Work')->assertJsonPath('object', 'folder')->json('id');
        $this->assertIsString($id);

        $this->putJson("/api/folders/{$id}", ['name' => 'Private'], $this->auth)->assertJsonPath('name', 'Private');
        $this->get("/api/folders/{$id}", $this->auth)->assertJsonPath('name', 'Private');
        $this->get('/api/folders', $this->auth)->assertJsonCount(1, 'data');

        FolderCipher::add($id, Random::uuid());
        $this->delete("/api/folders/{$id}", [], $this->auth)->assertStatus(200)->assertContent('')->assertHeaderMissing('Content-Type');
        $this->assertNull(Folder::findByUuidAndUser($id, $this->user->uuid));
        $this->assertSame(0, FolderCipher::query()->count());

        $this->get("/api/folders/{$id}", $this->auth)->assertStatus(400)->assertJsonPath('message', 'Invalid folder');
    }

    public function test_folders_of_other_users_are_invalid(): void
    {
        $other = Folder::new(Random::uuid(), 'Not yours');
        $other->save();

        $this->get("/api/folders/{$other->uuid}", $this->auth)->assertStatus(400)->assertJsonPath('message', 'Invalid folder');
        $this->putJson("/api/folders/{$other->uuid}", ['name' => 'Mine'], $this->auth)->assertStatus(400);
    }
}
