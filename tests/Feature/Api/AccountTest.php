<?php

namespace Tests\Feature\Api;

use App\Auth\AuthMethod;
use App\Auth\Jwt;
use App\Auth\LoginClaims;
use App\Models\Device;
use App\Models\Membership;
use App\Models\User;
use App\Support\Random;
use Tests\Concerns\UsesVaultwardenDatabase;
use Tests\TestCase;

/**
 * Profile, keys and revision date (src/api/core/accounts.rs).
 */
class AccountTest extends TestCase
{
    use UsesVaultwardenDatabase;

    private User $user;

    /**
     * @var array<string, string>
     */
    private array $auth;

    protected function vaultwardenEnvironment(): array
    {
        return ['PASSWORD_ITERATIONS' => '100000'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->useDatabase('sqlite');

        $this->user = User::new('kim@example.com', 'Kim');
        $this->user->setPassword('hash', 'user-key', resetSecurityStamp: false, allowNextRoute: null);
        $this->user->save();
        $device = Device::new(Random::uuid(), $this->user->uuid, 'cli', 25);
        $device->save();

        $jwt = $this->app->make(Jwt::class);
        $this->auth = ['Authorization' => 'Bearer '.LoginClaims::default($device, $this->user, AuthMethod::Password, 'cli', $jwt)->token($jwt)];
    }

    public function test_profile(): void
    {
        $response = $this->get('/api/accounts/profile', $this->auth)->assertStatus(200)
            ->assertJsonPath('email', 'kim@example.com')
            ->assertJsonPath('key', 'user-key')
            ->assertJsonPath('object', 'profile')
            ->assertJsonPath('_status', 0);
        $this->assertSame('_status', array_key_first((array) $response->json()));

        $this->putJson('/api/accounts/profile', ['name' => 'Kimberly'], $this->auth)->assertStatus(200)->assertJsonPath('name', 'Kimberly');
        $this->postJson('/api/accounts/profile', ['name' => str_repeat('x', 51)], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'The field Name must be a string with a maximum length of 50.');
        $this->putJson('/api/accounts/avatar', ['avatarColor' => '#123456'], $this->auth)->assertJsonPath('avatarColor', '#123456');
        $this->get('/api/accounts/profile', ['Accept' => 'application/json'])->assertStatus(401);
    }

    public function test_members_of_organizations_are_refused_until_organizations_exist(): void
    {
        (new Membership(['uuid' => Random::uuid(), 'user_uuid' => $this->user->uuid, 'org_uuid' => Random::uuid(), 'access_all' => false,
            'akey' => '', 'status' => Membership::STATUS_CONFIRMED, 'atype' => Membership::TYPE_USER, 'reset_password_key' => null,
            'external_id' => null, 'invited_by_email' => null]))->save();

        $this->get('/api/accounts/profile', $this->auth)->assertStatus(400)->assertJsonPath('message', 'Organizations are not implemented yet');
    }

    public function test_keys(): void
    {
        $this->get('/api/accounts/keys', $this->auth)->assertStatus(404)->assertJsonPath('message', 'User has no key pair');
        $this->postJson('/api/accounts/keys', ['encryptedPrivateKey' => 'private', 'publicKey' => 'public'], $this->auth)
            ->assertStatus(200)->assertContent('{"object":"keys","privateKey":"private","publicKey":"public"}');
        $this->postJson('/api/accounts/keys', ['encryptedPrivateKey' => 'x', 'publicKey' => 'y'], $this->auth)
            ->assertStatus(400)->assertJsonPath('message', 'User has existing keypair');
        $this->get('/api/accounts/keys', $this->auth)->assertStatus(200)->assertJsonPath('key', 'user-key')
            ->assertJsonPath('accountKeys.publicKeyEncryptionKeyPair.publicKey', 'public');

        $this->get("/api/users/{$this->user->uuid}/public-key", $this->auth)->assertJsonPath('publicKey', 'public');
        $this->get('/api/users/'.Random::uuid().'/public-key', $this->auth)->assertStatus(404)->assertJsonPath('message', "User doesn't exist");
        $this->get('/api/users/nope/public-key', ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_revision_date_in_milliseconds(): void
    {
        $this->get('/api/accounts/revision-date', $this->auth)->assertStatus(200)
            ->assertContent((string) (int) $this->user->updated_at->format('Uv'));
    }
}
